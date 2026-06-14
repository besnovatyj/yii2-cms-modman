<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle\handler;

use Besnovatyj\Helpers\FilesystemHelper;
use modules\modmanNew\catalog\PackageCatalog;
use modules\modmanNew\compiler\ConfigCompiler;
use modules\modmanNew\lifecycle\LifecycleLock;
use modules\modmanNew\lifecycle\OperationReport;
use modules\modmanNew\lifecycle\OperationType;
use modules\modmanNew\migration\MigrationOwnershipRepository;
use modules\modmanNew\migration\ModuleMigrationRunner;
use modules\modmanNew\registry\ModuleRegistry;
use modules\modmanNew\registry\ModuleState;
use modules\modmanNew\registry\ModuleStatus;
use Throwable;

/**
 * Восстановление после прерванных операций (самоисцеление).
 *
 * В старом modman журнал операции удалялся при любом исходе, поэтому крах PHP-процесса оставлял
 * неопределённое состояние без точки сверки. Здесь транзиентные/failed статусы в реестре — и есть
 * точка сверки: reconcile приводит «застрявший» модуль к чистому состоянию.
 *
 * Политика:
 *  - installing / removing / failed → откат к «не установлен» (revert миграций, удаление директорий,
 *    удаление записи реестра);
 *  - updating → возврат статуса в installed (миграции уже накатываются идемпотентно как pending),
 *    с предупреждением о ручной проверке версии.
 */
final class ReconcileHandler
{
    public function __construct(
        private readonly ModuleRegistry               $registry,
        private readonly PackageCatalog               $catalog,
        private readonly ModuleMigrationRunner        $runner,
        private readonly MigrationOwnershipRepository $owners,
        private readonly ConfigCompiler               $compiler,
        private readonly LifecycleLock                $lock,
    ) {}

    /**
     * @return array<string, ModuleState> модули, требующие сверки
     */
    public function pending(): array
    {
        return $this->registry->pendingStates();
    }

    public function reconcileAll(): OperationReport
    {
        $report = new OperationReport(OperationType::Reconcile, '*');

        $this->lock->withLock(function () use ($report): void {
            $pending = $this->registry->pendingStates();
            if ($pending === []) {
                $report->info('Незавершённых операций не найдено.');
                return;
            }

            foreach ($pending as $id => $state) {
                $report->step("Сверка '{$id}' (статус: {$state->status->value})");
                try {
                    $this->reconcileOne($id, $state, $report);
                } catch (Throwable $e) {
                    $report->error("'{$id}': {$e->getMessage()}");
                }
            }

            try {
                $this->compiler->recompile();
            } catch (Throwable $e) {
                $report->error('Перекомпиляция после сверки: ' . $e->getMessage());
            }
        });

        return $report;
    }

    private function reconcileOne(string $id, ModuleState $state, OperationReport $report): void
    {
        if ($state->status === ModuleStatus::Updating) {
            $this->registry->save($state->withStatus(ModuleStatus::Installed));
            $report->warning("'{$id}': прервано обновление — статус возвращён в installed; проверьте версию вручную.");
            return;
        }

        // installing / removing / failed → откат к «не установлен».
        $manifest = $this->catalog->findById($id);

        if ($manifest !== null && $manifest->contributions->hasMigrations()) {
            $this->runner->down($id, $manifest->contributions->migrationPath, $manifest->contributions->migrationNamespace);
        } else {
            $this->owners->forgetModule($id);
            $report->warning("'{$id}': файлы миграций недоступны — очищена только история владения.");
        }

        if ($manifest !== null) {
            foreach ($manifest->contributions->directories as $directory) {
                $path = $directory->resolvedPath();
                if (is_dir($path)) {
                    FilesystemHelper::deleteDirContents($path, true);
                }
            }
        }

        $this->registry->remove($id);
        $report->info("'{$id}': откачено к состоянию «не установлен».");
    }
}
