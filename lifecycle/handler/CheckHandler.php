<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle\handler;

use modules\modmanNew\catalog\PackageCatalog;
use modules\modmanNew\lifecycle\LifecyclePlanner;
use modules\modmanNew\lifecycle\OperationType;
use modules\modmanNew\lifecycle\plan\LifecyclePlan;
use modules\modmanNew\registry\ModuleRegistry;

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
