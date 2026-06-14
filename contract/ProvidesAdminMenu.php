<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\contract;

/**
 * Модуль предоставляет пункты меню админки.
 *
 * Формат — совместим со старым modman: либо один пункт `['label' => ..., '_meta' => [...]]`,
 * либо массив таких пунктов. Раскладку по locations/группам/приоритетам выполняет
 * {@see \modules\modman\compiler\MenuCompiler}.
 */
interface ProvidesAdminMenu
{
    public static function adminMenu(): array;
}
