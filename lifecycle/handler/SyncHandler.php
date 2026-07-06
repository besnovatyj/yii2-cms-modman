<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle\handler;

use modules\modman\catalog\PackageCatalog;
use modules\modman\compiler\ConfigCompiler;
use modules\modman\lifecycle\LifecycleLock;
use modules\modman\lifecycle\OperationReport;
use modules\modman\lifecycle\OperationType;
use modules\modman\migration\MigrationOwnershipRepository;
use modules\modman\registry\ModuleRegistry;
use modules\modman\registry\ModuleState;
use modules\modman\registry\ModuleStatus;
use Throwable;

/**
 * Пересборка реестра состояния (`modules-state.php`) из фактического состояния системы.
 *
 * Зачем: реестр — единый источник истины, но если его файл затёрли/побили, штатного способа
 * восстановить его «из реальности» не было ({@see ReconcileHandler} чинит только транзиентные
 * записи, уже присутствующие в реестре). Sync закрывает эту точку отказа: он **не** редактируется
 * руками и **не** требует ручных контрольных сумм — всё выводится автоматически:
 *
 *  - набор модулей — из каталога discovery ({@see PackageCatalog}: composer + скан `packages/`);
 *  - применённые миграции — из БД-истории владения ({@see MigrationOwnershipRepository});
 *  - `manifestChecksum` — пересчитывается детерминированно фабрикой манифестов (в каталоге).
 *
 * После пересборки запускается `recompile()` — производные конфиги собираются заново.
 *
 * Критерий «модуль установлен по факту» (для editable-модулей; системные в реестре не хранятся):
 *  1. уже `installed` в текущем реестре → запись обновляется (checksum/версия/миграции);
 *  2. за модулем числятся применённые миграции в БД → усыновляется как `installed`;
 *  3. `$adoptAll = true` → принудительно усыновить все обнаруженные (для голого восстановления);
 *  4. иначе установка не доказана → пропуск (используйте `install`).
 */
final class SyncHandler
{
    public function __construct(
        private readonly PackageCatalog               $catalog,
        private readonly ModuleRegistry               $registry,
        private readonly MigrationOwnershipRepository $owners,
        private readonly ConfigCompiler               $compiler,
        private readonly LifecycleLock                $lock,
    ) {}

    /**
     * @param bool $adoptAll усыновить как installed ВСЕ обнаруженные editable-модули, даже без
     *                       доказательств установки (миграций). Крайняя мера восстановления.
     */
    public function sync(bool $adoptAll = false): OperationReport
    {
        $report = new OperationReport(OperationType::Sync, '*');

        $this->lock->withLock(function () use ($report, $adoptAll): void {
            // Свежая discovery: подхватить только что установленные через composer пакеты.
            $this->catalog->refresh();
            $manifests = $this->catalog->manifests();

            $adopted = 0;
            $refreshed = 0;
            $skipped = 0;

            foreach ($manifests as $id => $manifest) {
                // Системные модули (editable=false) в реестр не пишутся — они всегда активны
                // и добавляются компилятором из каталога.
                if (!$manifest->editable) {
                    continue;
                }

                $existing = $this->registry->get($id);
                $applied = $this->owners->appliedVersions($id);
                $isActiveNow = $existing?->status->isActive() ?? false;

                $shouldInstall = $isActiveNow || $applied !== [] || $adoptAll;

                if (!$shouldInstall) {
                    $skipped++;
                    if ($existing === null && !$manifest->contributions->hasMigrations()) {
                        $report->warning(
                            "'{$id}': установка не подтверждена (нет миграций и записи в реестре) — пропущен. "
                            . 'Установите через install, либо повторите sync с --adoptAll.'
                        );
                    }
                    continue;
                }

                $now = time();
                $state = new ModuleState(
                    id: $id,
                    package: $manifest->package,
                    moduleClass: $manifest->moduleClass,
                    version: $manifest->version,
                    status: ModuleStatus::Installed,
                    operationId: ($existing !== null && $existing->operationId !== '')
                        ? $existing->operationId
                        : bin2hex(random_bytes(8)),
                    appliedMigrations: $applied,
                    manifestChecksum: $manifest->checksum,
                    installedAt: ($existing !== null && $existing->installedAt > 0) ? $existing->installedAt : $now,
                    updatedAt: $now,
                );
                $this->registry->save($state);

                if ($isActiveNow) {
                    $refreshed++;
                    $report->step("'{$id}': запись обновлена (v{$manifest->version->value}, миграций: " . count($applied) . ').');
                } else {
                    $adopted++;
                    $report->info("'{$id}': восстановлен как installed (v{$manifest->version->value}, миграций: " . count($applied) . ').');
                }
            }

            // Осиротевшие записи реестра (пакет исчез из каталога) — не удаляем молча, только сигналим.
            foreach ($this->registry->all() as $id => $state) {
                if (!isset($manifests[$id])) {
                    $report->warning(
                        "'{$id}': запись в реестре есть, но пакет не найден в каталоге — оставлена без изменений "
                        . '(удалите через uninstall при необходимости).'
                    );
                }
            }

            $report->step("Итог: усыновлено {$adopted}, обновлено {$refreshed}, пропущено {$skipped}.");

            try {
                $this->compiler->recompile();
                $report->info('Артефакты перекомпилированы из восстановленного реестра.');
            } catch (Throwable $e) {
                $report->error('Перекомпиляция после sync: ' . $e->getMessage());
            }
        });

        return $report;
    }
}
