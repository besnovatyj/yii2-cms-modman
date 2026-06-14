<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle\handler;

use modules\modmanNew\catalog\ModuleManifest;
use modules\modmanNew\catalog\PackageCatalog;
use modules\modmanNew\events\LifecyclePhase;
use modules\modmanNew\events\ModuleLifecycleDispatcher;
use modules\modmanNew\events\ModuleLifecycleEvent;
use modules\modmanNew\lifecycle\LifecycleExecutor;
use modules\modmanNew\lifecycle\LifecyclePlanner;
use modules\modmanNew\lifecycle\OperationContext;
use modules\modmanNew\lifecycle\OperationReport;
use modules\modmanNew\lifecycle\OperationType;
use modules\modmanNew\lifecycle\step\CreateDirectoriesStep;
use modules\modmanNew\lifecycle\step\RunMigrationsStep;
use modules\modmanNew\registry\ModuleRegistry;
use modules\modmanNew\registry\ModuleState;
use modules\modmanNew\registry\ModuleStatus;

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
