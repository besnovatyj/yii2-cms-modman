<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle\handler;

use modules\modman\catalog\PackageCatalog;
use modules\modman\lifecycle\LifecyclePlanner;
use modules\modman\lifecycle\OperationType;
use modules\modman\lifecycle\plan\LifecyclePlan;
use modules\modman\registry\ModuleRegistry;

/**
 * Проверка возможности операции (dry-run) — возвращает чистый {@see LifecyclePlan}, ничего не меняя.
 *
 * Если модуль уже установлен — планируется обновление, иначе установка.
 */
final class CheckHandler
{
    public function __construct(
        private readonly PackageCatalog   $catalog,
        private readonly ModuleRegistry   $registry,
        private readonly LifecyclePlanner $planner,
    ) {}

    public function check(string $moduleId): LifecyclePlan
    {
        $manifest = $this->catalog->findById($moduleId);
        if ($manifest === null) {
            return new LifecyclePlan(
                OperationType::Check,
                $moduleId,
                '',
                [],
                ["Модуль '{$moduleId}' не найден в каталоге."],
                [],
            );
        }

        return $this->registry->isInstalled($moduleId)
            ? $this->planner->planUpdate($manifest)
            : $this->planner->planInstall($manifest);
    }
}
