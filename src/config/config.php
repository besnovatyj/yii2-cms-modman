<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

return [
    'id' => 'Modman',
    'params' => [
        'iconClass' => 'bi bi-bricks',
        // GitHub-токен для проверки upstream-версий; переопределяется опцией modman_github_token
        // (модуль конфигурации). Пусто → анонимные запросы (лимит 60/час на IP).
        'githubToken' => '',
    ],
];
