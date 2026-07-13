<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Опции менеджера модулей для модуля конфигурации `yii2-cms-config`.
 *
 * Значение применяется в `modules.Modman.params.githubToken`, поэтому контроллёр читает его как
 * `Yii::$app->getModule('Modman')->params['githubToken']` — той же схемой, что и остальные модули.
 */
return [
    'modman_github_token' => [
        'path'        => 'modules.Modman.params.githubToken',
        'label'       => '[Modman] GitHub-токен для проверки версий (read-only)',
        'description' => 'Персональный токен GitHub (classic или fine-grained, только чтение публичных '
            . 'репозиториев). Без токена лимит проверки версий — 60 запросов/час на IP, с токеном — 5000. '
            . "Yii::\$app->getModule('Modman')->params['githubToken']",
        'category'    => 'Modman',
        'rules'       => [
            ['string'],
            ['match', 'pattern' => '~^[A-Za-z0-9_\-]*$~', 'message' => 'Токен содержит недопустимые символы.'],
        ],
        'inputOptions' => ['type' => 'input'],
    ],
];
