<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle\step;

use modules\modmanNew\lifecycle\OperationContext;
use modules\modmanNew\migration\ModuleMigrationRunner;

/**
 * Применяет миграции модуля (только ещё не применённые — pending). Подходит и для install, и для update.
 */
final class RunMigrationsStep implements LifecycleStep
{
    public function __construct(
        private readonly ModuleMigrationRunner $runner,
    ) {}

    public function describe(OperationContext $context): string
    {
        return 'Применить миграции БД модуля';
    }

    public function shouldRun(OperationContext $context): bool
    {
        return $context->manifest?->contributions->hasMigrations() === true;
    }

    public function execute(OperationContext $context): void
    {
        $contributions = $context->manifest->contributions;
        $applied = $this->runner->up(
            $context->moduleId,
            $contributions->migrationPath,
            $contributions->migrationNamespace,
        );

        $context->appliedMigrations = array_merge($context->appliedMigrations, $applied);
        $context->report->info(
            $applied === []
                ? 'Новых миграций нет.'
                : 'Применено миграций: ' . count($applied) . ' (' . implode(', ', $applied) . ').',
        );
    }

    public function compensate(OperationContext $context): void
    {
        if ($context->appliedMigrations === [] || $context->manifest === null) {
            return;
        }

        $contributions = $context->manifest->contributions;
        $reverted = $this->runner->revert(
            $context->moduleId,
            array_reverse($context->appliedMigrations),
            $contributions->migrationPath ?? '',
            $contributions->migrationNamespace,
        );
        $context->report->warning('Откат применённых миграций: ' . count($reverted) . '.');
        $context->appliedMigrations = [];
    }
}
