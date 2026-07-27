<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog;

/**
 * Вклады модуля в приложение — то, что модуль добавляет в производные конфиги при установке.
 *
 * Собирается {@see ManifestFactory} из реализованных модулем capability-контрактов. Поля, для
 * которых модуль не реализовал контракт, остаются пустыми. Компилятор {@see \Besnovatyj\Modman\compiler\ConfigCompiler}
 * читает этот объект, а не дёргает методы модуля.
 */
final readonly class Contributions
{
    /**
     * @param array<string, array>   $components   componentId => конфиг
     * @param array<int, string>      $bootstrap    список bootstrap-классов
     * @param array                   $adminMenu    пункты меню (формат ProvidesAdminMenu)
     * @param array<int, \Besnovatyj\Contracts\dashboard\DashboardWidgetDescriptor> $dashboardWidgets
     *        плитки главной панели админки (формат ProvidesDashboardWidgets)
     * @param array                   $options      опции модуля
     * @param array<string, array>    $logChannels  channelId => спека
     * @param array<int, RequiredDirectory> $directories требуемые директории
     * @param array<string, array> $appConfig appId => частичное дерево конфига приложения (ключи 1:1
     *        с Yii-конфигом), уже пропущенное через allowlist политики {@see ManifestFactory}: разрешены
     *        `components`, `params` и `as access.allowActions`; `as access.class` и прочее вырезаны
     * @param array<string, string|string[]> $configPlugin `extra.config-plugin` пакета (group => file|files)
     *        для merge-plan (yiisoft/config). Пути относительны корня пакета. См. {@see \Besnovatyj\Modman\compiler\MergePlanCompiler}.
     */
    public function __construct(
        public array   $components = [],
        public array   $bootstrap = [],
        public array   $adminMenu = [],
        public array   $dashboardWidgets = [],
        public array   $options = [],
        public array   $logChannels = [],
        public array   $directories = [],
        public array   $appConfig = [],
        public array   $configPlugin = [],
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
