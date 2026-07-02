<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\contract\_backup;

/**
 * Модуль предоставляет настраиваемые опции (для модуля конфигурации `yii2-cms-config`).
 */
interface ProvidesOptions
{
    public static function options(): array;
}
