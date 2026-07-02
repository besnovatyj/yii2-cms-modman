<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\contract\_backup;

/**
 * Модуль добавляет классы в bootstrap приложения.
 *
 * Каждый класс должен реализовывать {@see \yii\base\BootstrapInterface}. Проверка типа выполняется
 * на этапе планирования операции, а не молча при записи (как в старом modman).
 */
interface ProvidesBootstrap
{
    /**
     * @return array<int, class-string<\yii\base\BootstrapInterface>>
     */
    public static function bootstrapClasses(): array;
}
