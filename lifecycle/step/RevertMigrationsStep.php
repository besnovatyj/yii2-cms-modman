<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle\step;

use modules\modmanNew\lifecycle\OperationContext;
use modules\modmanNew\migration\MigrationOwnershipRepository;
use modules\modmanNew\migration\ModuleMigrationRunner;

/**
 * Откатывает ВСЕ миграции, принадлежащие модулю (для uninstall). Откат строго по владельцу
 * ({@see MigrationOwnershipRepository}), а не по эвристике «всё в каталоге», как в старом modman.
 */
final class RevertMigrationsStep implements LifecycleStep
{
    public function __construct(
        private readonly ModuleMigrationRunner       $runner,
        private readonly MigrationOwnershipRepository $owners,
    ) {}

    public function describe(OperationContext $context): string
    {
        return 'Откатить миграции БД модуля';
    }

    public function shouldRun(OperationContext $context): bool
    {
        return $this->owners->appliedVersions($context->moduleId) !== [];
    }

    public function execute(OperationContext $context): void
    {
        $manifest = $context->manifest;

        if ($manifest !== null && $manifest->contributions->hasMigrations()) {
            $reverted = $this->runner->down(
                $context->moduleId,
                $manifest->contributions->migrationPath,
                $manifest->contributions->migrationNamespace,
            );
            $context->report->info('Откачено миграций: ' . count($reverted) . '.');
            return;
        }

        // Пакет/файлы миграций недоступны — откатить down() нельзя, чистим только владение.
        $this->owners->forgetModule($context->moduleId);
        $context->report->warning(
            'Файлы миграций недоступны: очищена история владения, но таблицы БД могли остаться. '
            . 'Проверьте вручную или через reconcile.'
        );
    }

    public function compensate(OperationContext $context): void
    {
        // Повторное применение откаченных миграций автоматически не выполняется: при сбое uninstall
        // модуль помечается failed и разбирается через reconcile (см. ReconcileHandler).
        $context->report->warning('Автокомпенсация отката миграций не выполняется (см. reconcile).');
    }
}
