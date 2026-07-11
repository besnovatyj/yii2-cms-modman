<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\lifecycle;

use Besnovatyj\Modman\catalog\ModuleManifest;
use Besnovatyj\Modman\registry\ModuleState;

/**
 * Контекст выполнения операции — носитель данных между шагами.
 *
 * Шаги ({@see step\LifecycleStep}) — сервисы со своими зависимостями; контекст передаёт им данные
 * операции и аккумулирует то, что нужно для компенсации (применённые миграции, созданные директории).
 */
final class OperationContext
{
    /** @var string[] версии миграций, применённых в ходе операции (для компенсации) */
    public array $appliedMigrations = [];

    /** @var string[] абсолютные пути директорий, созданных в ходе операции (для компенсации) */
    public array $createdDirectories = [];

    public function __construct(
        public readonly OperationType    $type,
        public readonly string           $moduleId,
        public readonly string           $operationId,
        public readonly ?ModuleManifest  $manifest,
        public readonly ?ModuleState     $previousState,
        public readonly OperationReport  $report,
    ) {}
}
