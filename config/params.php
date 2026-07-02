<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Параметры системы управления модулями.
 *
 * На cutover достаточно заменить пути на канонические и вызвать recompile.
 */
return [
    // Единый источник истины — реестр состояния (атомарный lock-файл).
    'lockFile' => '@config-dyn-gen/modules-state.php',

    // Директории discovery (источник Filesystem). Composer-источник читает vendor/composer/installed.json.
    'scanDirs' => [
        '@root/packages/besnovatyj',
        '@modules',
    ],

    // Производные артефакты конфигурации (компилируются целиком из реестра).
    'artifacts' => [
        'modules' => '@config-dyn-gen/modulesConfigFile.php',
        'bootstrap' => '@config-dyn-gen/bootstrapComponentsAndModulesConfigFile.php',
        'components' => '@config-dyn-gen/componentsConfigFile.php',
        'logChannels' => '@config-dyn-gen/logChannelsConfigFile.php',
        'options' => '@config-dyn-gen/moduleOptions.php',
        // Тема-НЕзависимый манифест источников представлений (см. common params 'moduleViewSourcesFile').
        'viewSources' => '@config-dyn-gen/moduleViewSources.php',
    ],

    // Локации меню (location => файл/включённость). Совпадает по смыслу с конфигом старого modman.
    'menuLocations' => [
        'left-sidebar' => ['file' => '@config-dyn-gen/menu-left-sidebar.php', 'enabled' => true],
        'right-sidebar' => ['file' => '@config-dyn-gen/menu-right-sidebar.php', 'enabled' => true],
        'top-menu' => ['file' => '@config-dyn-gen/menu-top.php', 'enabled' => false],
        'header-quick-links' => ['file' => '@config-dyn-gen/menu-header-quick.php', 'enabled' => false],
        'footer-menu' => ['file' => '@config-dyn-gen/menu-footer.php', 'enabled' => false],
    ],

    'menuDefaults' => [
        'defaultPriority' => 500,
        'defaultGroupPriority' => 500,
    ],

    // Путь для файлового мьютекса lifecycle-операций.
    'mutexPath' => '@runtime/modman_mutex',
];
