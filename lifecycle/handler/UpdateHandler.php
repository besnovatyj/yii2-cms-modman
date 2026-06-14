<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle\handler;

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
 * Обновление установленного модуля.
 *
 * Применяет ТОЛЬКО pending-миграции (без down→up) — благодаря учёту владения миграциями. Это и есть
 * полноценный update lifecycle, которого не было в старом modman (там обновление = переустановка).
 */
final class UpdateHandler
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

    public function update(string $moduleId): OperationReport
    {
        $report = new OperationReport(OperationType::Update, $moduleId);

        $manifest = $this->catalog->findById($moduleId);
        if ($manifest === null) {
            $report->error("Модуль '{$moduleId}' не найден в каталоге.");
            return $report;
        }

        $state = $this->registry->get($moduleId);
        if ($state === null || !$state->status->isActive()) {
            $report->error("Модуль '{$moduleId}' не установлен — обновлять нечего.");
            return $report;
        }

        $plan = $this->planner->planUpdate($manifest);
        if (!$plan->isFeasible()) {
            foreach ($plan->blockers as $blocker) {
                $report->error($blocker);
            }
            return $report;
        }

        $operationId = bin2hex(random_bytes(8));
        $context = new OperationContext(
            OperationType::Update,
            $moduleId,
            $operationId,
            $manifest,
            $state,
            $report,
        );

        $this->registry->save($state->withStatus(ModuleStatus::Updating, $operationId));
        $this->events->dispatch(new ModuleLifecycleEvent(LifecyclePhase::BeforeUpdate, $moduleId, $manifest));

        $commit = function (OperationContext $ctx) use ($manifest, $state, $operationId): void {
            $merged = array_values(array_unique(array_merge($state->appliedMigrations, $ctx->appliedMigrations)));
            $this->registry->save(new ModuleState(
                id: $manifest->id,
                package: $manifest->package,
                moduleClass: $manifest->moduleClass,
                version: $manifest->version,
                status: ModuleStatus::Installed,
                operationId: $operationId,
                appliedMigrations: $merged,
                manifestChecksum: $manifest->checksum,
                installedAt: $state->installedAt,
                updatedAt: time(),
            ));
        };

        // Откат: вернуть предыдущее установленное состояние (версия/миграции/checksum как были).
        $rollback = function () use ($state): void {
            $this->registry->save($state->withStatus(ModuleStatus::Installed));
        };

        $this->executor->execute($context, [$this->runMigrations, $this->createDirectories], $commit, $rollback);

        if ($report->isSuccessful()) {
            $this->events->dispatch(new ModuleLifecycleEvent(LifecyclePhase::AfterUpdate, $moduleId, $manifest));
            $report->info("Модуль '{$moduleId}' обновлён до v{$manifest->version->value}.");
        }

        return $report;
    }
}
