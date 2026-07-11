<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman;

use Besnovatyj\Modman\catalog\PackageCatalog;
use Besnovatyj\Modman\catalog\source\DiscoveredPackage;
use Besnovatyj\Modman\compiler\CompiledArtifacts;
use Besnovatyj\Modman\compiler\ConfigCompiler;
use Besnovatyj\Modman\lifecycle\handler\CheckHandler;
use Besnovatyj\Modman\lifecycle\handler\InstallHandler;
use Besnovatyj\Modman\lifecycle\handler\ReconcileHandler;
use Besnovatyj\Modman\lifecycle\handler\SyncHandler;
use Besnovatyj\Modman\lifecycle\handler\UninstallHandler;
use Besnovatyj\Modman\lifecycle\handler\UpdateHandler;
use Besnovatyj\Modman\lifecycle\OperationReport;
use Besnovatyj\Modman\lifecycle\plan\LifecyclePlan;
use Besnovatyj\Modman\registry\ModuleRegistry;
use Besnovatyj\Modman\registry\ModuleState;
use Besnovatyj\Modman\registry\ModuleStatus;
use Yii;

/**
 * Фасад системы управления модулями — единый публичный API для драйверов (web-контроллёр, console).
 *
 * Тонкая обёртка над хендлерами и каталогом/реестром: драйверы остаются без бизнес-логики, а сама
 * логика одинаково доступна из web, CLI, очереди и Ansible (благодаря {@see OperationReport}).
 */
final class ModuleManager
{
    public function __construct(
        private readonly PackageCatalog    $catalog,
        private readonly ModuleRegistry    $registry,
        private readonly CheckHandler      $checkHandler,
        private readonly InstallHandler    $installHandler,
        private readonly UninstallHandler  $uninstallHandler,
        private readonly UpdateHandler     $updateHandler,
        private readonly ReconcileHandler  $reconcileHandler,
        private readonly SyncHandler       $syncHandler,
        private readonly ConfigCompiler    $compiler,
    ) {}

    public function check(string $moduleId): LifecyclePlan
    {
        return $this->checkHandler->check($moduleId);
    }

    /**
     * План удаления модуля (dry-run, ничего не меняет).
     */
    public function checkUninstall(string $moduleId): LifecyclePlan
    {
        return $this->checkHandler->checkUninstall($moduleId);
    }

    public function install(string $moduleId): OperationReport
    {
        $report = $this->installHandler->install($moduleId);
        $this->catalog->refresh();
        return $report;
    }

    public function uninstall(string $moduleId): OperationReport
    {
        $report = $this->uninstallHandler->uninstall($moduleId);
        $this->catalog->refresh();
        return $report;
    }

    public function update(string $moduleId): OperationReport
    {
        $report = $this->updateHandler->update($moduleId);
        $this->catalog->refresh();
        return $report;
    }

    public function reconcile(): OperationReport
    {
        return $this->reconcileHandler->reconcileAll();
    }

    /**
     * Пересобрать реестр состояния из фактического состояния системы (каталог + история миграций)
     * и перекомпилировать артефакты. Восстановление после потери/повреждения `modules-state.php`
     * без ручной правки и без ручных контрольных сумм.
     *
     * @param bool $adoptAll усыновить как installed ВСЕ обнаруженные editable-модули, даже без
     *                       доказательств установки (крайняя мера восстановления).
     */
    public function sync(bool $adoptAll = false): OperationReport
    {
        $report = $this->syncHandler->sync($adoptAll);
        $this->catalog->refresh();
        return $report;
    }

    /**
     * Принудительно перекомпилировать артефакты из текущего реестра (кнопка «пересобрать»).
     */
    public function recompile(): CompiledArtifacts
    {
        return $this->compiler->recompile();
    }

    /**
     * Модули для UI: манифесты каталога, наложенные на реестр, плюс «осиротевшие» записи реестра.
     *
     * @return ModuleView[]
     */
    public function modules(): array
    {
        $views = [];
        $manifests = $this->catalog->manifests();

        foreach ($manifests as $id => $manifest) {
            $state = $this->registry->get($id);
            // Системный модуль (editable=false): часть ядра, ставится установочным скриптом, из админки
            // неприкасаем. Сам менеджер — такой же: бутстрапится приложением вручную, в реестр не пишется.
            $system = !$manifest->editable;
            // Реальная активность системного модуля — это его фактическая регистрация в приложении,
            // а НЕ просто наличие пакета на диске (иначе любой не-editable пакет выглядел бы «системным
            // активным»). Для editable-модулей источник истины — реестр.
            $systemActive = $system && Yii::$app->hasModule($id);
            $installed = $state?->status->isActive() ?? $systemActive;
            // hasUpdate имеет смысл только для управляемого (editable) модуля с записью в реестре.
            $hasUpdate = $state !== null
                && $state->status->isActive()
                && ($manifest->version->isGreaterThan($state->version) || $manifest->checksum !== $state->manifestChecksum);

            $views[] = new ModuleView(
                id: $id,
                package: $manifest->package,
                availableVersion: $manifest->version->value,
                installedVersion: $state?->version->value ?? ($systemActive ? $manifest->version->value : null),
                status: $state?->status->value ?? ($systemActive ? 'system' : ModuleStatus::Discovered->value),
                editable: $manifest->editable,
                installed: $installed,
                hasUpdate: $hasUpdate,
                iconClass: $manifest->iconClass,
                hasOptions: $manifest->contributions->options !== [],
                system: $systemActive,
            );
        }

        // CMS-модули с ошибкой конфигурации — строкой с причиной и без активной кнопки установки.
        foreach ($this->catalog->invalids() as $invalid) {
            $views[] = new ModuleView(
                id: $invalid->declaredId ?? $invalid->package,
                package: $invalid->package,
                availableVersion: '',
                installedVersion: null,
                status: 'invalid',
                editable: false,
                installed: false,
                hasUpdate: false,
                invalid: true,
                invalidReason: $invalid->reason,
            );
        }

        // Записи реестра, для которых пропал пакет (осиротевшие) — показываем для диагностики/reconcile.
        foreach ($this->registry->all() as $id => $state) {
            if (!isset($manifests[$id])) {
                $views[] = new ModuleView(
                    id: $id,
                    package: $state->package,
                    availableVersion: '',
                    installedVersion: $state->version->value,
                    status: $state->status->value,
                    editable: false,
                    installed: $state->status->isActive(),
                    hasUpdate: false,
                    orphan: true,
                );
            }
        }

        usort($views, static fn(ModuleView $a, ModuleView $b): int => strcmp($a->id, $b->id));
        return $views;
    }

    /**
     * @return DiscoveredPackage[]
     */
    public function packages(): array
    {
        return $this->catalog->packages();
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->catalog->warnings();
    }

    /**
     * @return array<string, ModuleState>
     */
    public function pending(): array
    {
        return $this->reconcileHandler->pending();
    }
}
