<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Параметры новой системы управления модулями.
 *
 * Артефакты компилируются в файлы с суффиксом `_new` — это параметр СОСУЩЕСТВОВАНИЯ со старым modman,
 * а не временный хак. На cutover достаточно заменить пути на канонические и вызвать recompile.
 */
return [
    // Единый источник истины — реестр состояния (атомарный lock-файл).
    'lockFile' => '@config-dyn-gen/modules-state_new.php',

    // Директории discovery (источник Filesystem). Composer-источник читает vendor/composer/installed.json.
    'scanDirs' => [
        '@root/packages/besnovatyj',
        '@modules',
    ],

    // Производные артефакты конфигурации (компилируются целиком из реестра).
    'artifacts' => [
        'modules' => '@config-dyn-gen/modulesConfigFile_new.php',
        'bootstrap' => '@config-dyn-gen/bootstrapComponentsAndModulesConfigFile_new.php',
        'components' => '@config-dyn-gen/componentsConfigFile_new.php',
        'logChannels' => '@config-dyn-gen/logChannelsConfigFile_new.php',
        'options' => '@config-dyn-gen/moduleOptions_new.php',
    ],

    // Локации меню (location => файл/включённость). Совпадает по смыслу с конфигом старого modman.
    'menuLocations' => [
        'left-sidebar' => ['file' => '@config-dyn-gen/menu-left-sidebar_new.php', 'enabled' => true],
        'right-sidebar' => ['file' => '@config-dyn-gen/menu-right-sidebar_new.php', 'enabled' => true],
        'top-menu' => ['file' => '@config-dyn-gen/menu-top_new.php', 'enabled' => false],
        'header-quick-links' => ['file' => '@config-dyn-gen/menu-header-quick_new.php', 'enabled' => false],
        'footer-menu' => ['file' => '@config-dyn-gen/menu-footer_new.php', 'enabled' => false],
    ],

    'menuDefaults' => [
        'defaultPriority' => 500,
        'defaultGroupPriority' => 500,
    ],

    // Путь для файлового мьютекса lifecycle-операций.
    'mutexPath' => '@runtime/modman_new_mutex',
];
