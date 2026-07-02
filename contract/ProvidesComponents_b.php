<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\contract\_backup;

/**
 * Модуль регистрирует компоненты приложения.
 *
 * Формат — как у Yii `components`: ['componentId' => [конфиг], ...]. Компилятор сольёт их в
 * общий артефакт компонентов; конфликт имён компонентов отлавливается на этапе планирования.
 */
interface ProvidesComponents
{
    /**
     * @return array<string, array> componentId => конфиг компонента
     */
    public static function components(): array;
}
