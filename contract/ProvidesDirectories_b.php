<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\contract\_backup;

/**
 * Модуль требует директории на домене статики для своей работы.
 *
 * Возвращается простой массив, который {@see \modules\modman\catalog\ManifestFactory} обернёт
 * в набор {@see \modules\modman\catalog\RequiredDirectory}. Допустимые формы элементов:
 * - строка-путь (может содержать alias, например '@static/origin/Blog'); режим по умолчанию 0775;
 * - пара 'путь' => 0755 (явный режим).
 *
 * Если модулю нужна сложная структура — он создаёт её сам внутри выделенных директорий.
 */
interface ProvidesDirectories
{
    /**
     * @return array<int|string, string|int> список путей или мапа путь=>режим
     */
    public static function directories(): array;
}
