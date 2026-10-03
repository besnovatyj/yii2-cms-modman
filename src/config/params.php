<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Параметры системы управления модулями.
 */
return [
    // Единый источник истины — реестр состояния (атомарный lock-файл).
    'lockFile' => '@config-dyn-gen/modules-state.php',

    // Производные артефакты modman. Yii-конфиг приложения (modules/components/bootstrap/меню админки)
    // собирает движок yiisoft/config по merge-plan, отдельных артефактов у него нет.
    'artifacts' => [
        // Registry-gated лог-каналы устанавливаемых модулей (см. common/config/log.php).
        'logChannels' => '@config-dyn-gen/logChannelsConfigFile.php',
        // Реестр опций (агрегат Module::options()) для модуля конфигурации `besnovatyj/yii2-cms-config`.
        'options' => '@config-dyn-gen/moduleOptions.php',
        // Тема-НЕзависимый манифест источников представлений (см. common params 'moduleViewSourcesFile').
        'viewSources' => '@config-dyn-gen/moduleViewSources.php',
        // Плитки главной панели админки активных модулей (потребляется модулем `besnovatyj/yii2-cms-dashboard`).
        'dashboardWidgets' => '@config-dyn-gen/dashboardWidgets.php',
        // Merge-plan для движка yiisoft/config (Yii3): [env][group][package][]=file. Читается
        // рантайм-обёрткой common\config\ConfigFactory. См. MergePlanCompiler.
        'mergePlan' => '@config-dyn-gen/merge-plan.php',
    ],

    // Путь для файлового мьютекса lifecycle-операций.
    'mutexPath' => '@runtime/modman_mutex',

    // Module-пакеты, которым L1-bootstrap (extra.bootstrap) разрешён осознанно — без предупреждения
    // WarningModule. Единственный легитимный кейс: логика, обязанная жить вне гейта modman
    // (например, подписка на фазы lifecycle самого менеджера). См. catalog/check/L1BootstrapCheck.
    'l1BootstrapAllowlist' => [],

    // Проверка последней версии пакета на GitHub (см. upstream/GitHubTagFetcher).
    'upstream' => [
        'ttl'      => 3600, // кэш успешного ответа, сек
        'errorTtl' => 300,  // кэш ошибки/лимита, сек
        'timeout'  => 5,    // таймаут HTTP-запроса, сек
    ],
];
