<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\contract;

/**
 * Модуль объявляет свои зависимости.
 *
 * Возвращается простой массив (не value-объект менеджера), чтобы модуль не зависел от внутренних
 * классов `modmanNew`. {@see \modules\modmanNew\catalog\ManifestFactory} обернёт его в
 * {@see \modules\modmanNew\catalog\Requirements}.
 *
 * Формат:
 * ```php
 * [
 *     'modules'        => ['Menu', 'File:>=1.2.0'], // id модуля или id:constraint
 *     'php_extensions' => ['gd', 'intl'],
 *     'php_version'    => '>=8.4',
 *     'yii_version'    => '>=2.0.45',
 * ]
 * ```
 */
interface ProvidesDependencies
{
    public static function dependencies(): array;
}
