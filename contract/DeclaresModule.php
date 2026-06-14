<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\contract;

/**
 * Ядро контракта модуля, управляемого системой.
 *
 * Методы намеренно СТАТИЧЕСКИЕ: discovery читает метаданные модуля, не инстанцируя Yii-модуль
 * (и, следовательно, не запуская его {@see \yii\base\Module::init()} с побочными эффектами).
 * При этом, в отличие от старого `modman`, это типизированный контракт: наличие возможности
 * проверяется через `class_implements()`/`instanceof`, а не через `method_exists()`.
 *
 * Дополнительные возможности модуля объявляются реализацией capability-интерфейсов
 * {@see ProvidesComponents}, {@see ProvidesMigrations} и т.д. — каждый модуль реализует ровно то,
 * что он действительно предоставляет.
 *
 * @see \modules\modman\catalog\ManifestFactory сборка {@see \modules\modman\catalog\ModuleManifest}
 */
interface DeclaresModule
{
    /**
     * Стабильный идентификатор модуля (ключ в конфигурации Yii `modules`).
     * Должен совпадать с `extra.moduleId` в composer.json пакета.
     */
    public static function moduleId(): string;

    /**
     * Семантическая версия модуля (например, '1.2.3').
     * Источник истины о версии при установке/обновлении.
     */
    public static function moduleVersion(): string;

    /**
     * Базовая конфигурация Yii-модуля: ['id' => ..., 'params' => [...], ...].
     * НЕ содержит 'class' и 'version' — их добавляет компилятор из манифеста.
     */
    public static function moduleConfig(): array;

    /**
     * Можно ли управлять модулем (устанавливать/удалять/обновлять) через менеджер.
     * Системные модули возвращают false и не могут быть удалены.
     */
    public static function isEditable(): bool;
}
