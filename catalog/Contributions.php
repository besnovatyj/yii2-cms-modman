<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\catalog;

/**
 * Вклады модуля в приложение — то, что модуль добавляет в производные конфиги при установке.
 *
 * Собирается {@see ManifestFactory} из реализованных модулем capability-контрактов. Поля, для
 * которых модуль не реализовал контракт, остаются пустыми. Компилятор {@see \modules\modman\compiler\ConfigCompiler}
 * читает этот объект, а не дёргает методы модуля.
 */
final readonly class Contributions
{
    /**
     * @param array<string, array>   $components   componentId => конфиг
     * @param array<int, string>      $bootstrap    список bootstrap-классов
     * @param array                   $adminMenu    пункты меню (формат ProvidesAdminMenu)
     * @param array                   $options      опции модуля
     * @param array<string, array>    $logChannels  channelId => спека
     * @param array<int, RequiredDirectory> $directories требуемые директории
     */
    public function __construct(
        public array   $components = [],
        public array   $bootstrap = [],
        public array   $adminMenu = [],
        public array   $options = [],
        public array   $logChannels = [],
        public array   $directories = [],
        public ?string $migrationPath = null,
        public ?string $migrationNamespace = null,
    ) {}

    public function hasMigrations(): bool
    {
        return $this->migrationPath !== null;
    }

    public function hasAdminMenu(): bool
    {
        return $this->adminMenu !== [];
    }
}
