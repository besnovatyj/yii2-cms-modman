<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle\handler;

use modules\modman\catalog\PackageCatalog;
use modules\modman\events\LifecyclePhase;
use modules\modman\events\ModuleLifecycleDispatcher;
use modules\modman\events\ModuleLifecycleEvent;
use modules\modman\lifecycle\LifecycleExecutor;
use modules\modman\lifecycle\LifecyclePlanner;
use modules\modman\lifecycle\OperationContext;
use modules\modman\lifecycle\OperationReport;
use modules\modman\lifecycle\OperationType;
use modules\modman\lifecycle\step\RemoveDirectoriesStep;
use modules\modman\lifecycle\step\RevertMigrationsStep;
use modules\modman\registry\ModuleRegistry;
use modules\modman\registry\ModuleStatus;

/**
 * Удаление модуля.
 *
 * Перед удалением проверяются обратные зависимости (через планировщик) — нельзя удалить модуль, на
 * котором держатся другие. При сбое модуль помечается failed (для последующего reconcile), а не
 * остаётся в неопределённом состоянии.
 */
final class UninstallHandler
{
    public function __construct(
        private readonly PackageCatalog            $catalog,
        private readonly ModuleRegistry            $registry,
        private readonly LifecyclePlanner          $planner,
        private readonly LifecycleExecutor         $executor,
        private readonly ModuleLifecycleDispatcher $events,
        private readonly RevertMigrationsStep      $revertMigrations,
        private readonly RemoveDirectoriesStep     $removeDirectories,
    ) {}

    public function uninstall(string $moduleId): OperationReport
    {
        $report = new OperationReport(OperationType::Uninstall, $moduleId);

        $state = $this->registry->get($moduleId);
        if ($state === null || !$state->status->isActive()) {
            $report->error("Модуль '{$moduleId}' не установлен.");
            return $report;
        }

        $manifest = $this->catalog->findById($moduleId);

        $plan = $this->planner->planUninstall($moduleId);
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
            OperationType::Uninstall,
            $moduleId,
            $operationId,
            $manifest,
            $state,
            $report,
        );

        // Маркер «удаление началось» — под блокировкой (внутри executor), чтобы не было гонки
        // с параллельным запросом до взятия мьютекса.
        $intent = function () use ($state, $manifest, $moduleId, $operationId): void {
            $this->registry->save($state->withStatus(ModuleStatus::Removing, $operationId));
            $this->events->dispatch(new ModuleLifecycleEvent(LifecyclePhase::BeforeUninstall, $moduleId, $manifest));
        };

        $commit = function () use ($moduleId): void {
            $this->registry->remove($moduleId);
        };

        // Прерванное удаление помечаем failed — будет разобрано через reconcile.
        $rollback = function () use ($state, $operationId): void {
            $this->registry->save($state->withStatus(ModuleStatus::Failed, $operationId));
        };

        $this->executor->execute($context, [$this->revertMigrations, $this->removeDirectories], $commit, $rollback, $intent);

        if ($report->isSuccessful()) {
            $this->events->dispatch(new ModuleLifecycleEvent(LifecyclePhase::AfterUninstall, $moduleId, $manifest));
            $report->info("Модуль '{$moduleId}' удалён.");
        }

        return $report;
    }
}
