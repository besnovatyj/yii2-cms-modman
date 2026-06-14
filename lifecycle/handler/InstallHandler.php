<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle\handler;

use modules\modman\catalog\ModuleManifest;
use modules\modman\catalog\PackageCatalog;
use modules\modman\events\LifecyclePhase;
use modules\modman\events\ModuleLifecycleDispatcher;
use modules\modman\events\ModuleLifecycleEvent;
use modules\modman\lifecycle\LifecycleExecutor;
use modules\modman\lifecycle\LifecyclePlanner;
use modules\modman\lifecycle\OperationContext;
use modules\modman\lifecycle\OperationReport;
use modules\modman\lifecycle\OperationType;
use modules\modman\lifecycle\step\CreateDirectoriesStep;
use modules\modman\lifecycle\step\RunMigrationsStep;
use modules\modman\registry\ModuleRegistry;
use modules\modman\registry\ModuleState;
use modules\modman\registry\ModuleStatus;

/**
 * Установка модуля.
 *
 * Поток: валидация через планировщик → write-ahead маркер (Installing) → шаги (миграции, директории)
 * → commit-at-end (Installed) → recompile. Сбой откатывается исполнителем; домашние ошибки попадают в
 * {@see OperationReport} (без исключений в нормальном потоке).
 */
final class InstallHandler
{
    public function __construct(
        private readonly PackageCatalog            $catalog,
        private readonly ModuleRegistry            $registry,
        private readonly LifecyclePlanner          $planner,
        private readonly LifecycleExecutor         $executor,
        private readonly ModuleLifecycleDispatcher $events,
        private readonly RunMigrationsStep         $runMigrations,
        private readonly CreateDirectoriesStep     $createDirectories,
    ) {}

    public function install(string $moduleId): OperationReport
    {
        $report = new OperationReport(OperationType::Install, $moduleId);

        $manifest = $this->catalog->findById($moduleId);
        if ($manifest === null) {
            $report->error("Модуль '{$moduleId}' не найден в каталоге.");
            return $report;
        }

        $plan = $this->planner->planInstall($manifest);
        if (!$plan->isFeasible()) {
            foreach ($plan->blockers as $blocker) {
                $report->error($blocker);
            }
            return $report;
        }
        foreach ($plan->warnings as $warning) {
            $report->warning($warning);
        }

        $operationId = bin2hex(random_bytes(8));
        $context = new OperationContext(
            OperationType::Install,
            $moduleId,
            $operationId,
            $manifest,
            $this->registry->get($moduleId),
            $report,
        );

        // write-ahead intent: незавершённая установка будет видна reconcile.
        $this->registry->save($this->intentState($manifest, $operationId));
        $this->events->dispatch(new ModuleLifecycleEvent(LifecyclePhase::BeforeInstall, $moduleId, $manifest));

        $commit = function (OperationContext $ctx) use ($manifest, $operationId): void {
            $now = time();
            $this->registry->save(new ModuleState(
                id: $manifest->id,
                package: $manifest->package,
                moduleClass: $manifest->moduleClass,
                version: $manifest->version,
                status: ModuleStatus::Installed,
                operationId: $operationId,
                appliedMigrations: $ctx->appliedMigrations,
                manifestChecksum: $manifest->checksum,
                installedAt: $now,
                updatedAt: $now,
            ));
        };

        // Новый модуль: откат = убрать запись (включая intent-маркер).
        $rollback = function () use ($moduleId): void {
            $this->registry->remove($moduleId);
        };

        $this->executor->execute($context, [$this->runMigrations, $this->createDirectories], $commit, $rollback);

        if ($report->isSuccessful()) {
            $this->events->dispatch(new ModuleLifecycleEvent(LifecyclePhase::AfterInstall, $moduleId, $manifest));
            $report->info("Модуль '{$moduleId}' установлен (v{$manifest->version->value}).");
        }

        return $report;
    }

    private function intentState(ModuleManifest $manifest, string $operationId): ModuleState
    {
        $now = time();
        return new ModuleState(
            id: $manifest->id,
            package: $manifest->package,
            moduleClass: $manifest->moduleClass,
            version: $manifest->version,
            status: ModuleStatus::Installing,
            operationId: $operationId,
            appliedMigrations: [],
            manifestChecksum: $manifest->checksum,
            installedAt: $now,
            updatedAt: $now,
        );
    }
}
