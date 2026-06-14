<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Пункт меню админки для самого менеджера (для ручного добавления в меню приложения при желании).
 */
return [
    'label' => 'Модули (new)',
    'iconClass' => 'bi bi-bricks me-1',
    'url' => ['/modmanNew/backend/modules/index'],
    'active' => static function (): bool {
        return str_contains(\Yii::$app->request->url, 'modmanNew/backend/modules');
    },
    '_meta' => [
        'placements' => [
            [
                'location' => 'right-sidebar',
                'group' => 'Service',
                'groupIcon' => 'bi bi-sliders',
                'priority' => 90,
                'groupPriority' => 100,
            ],
        ],
    ],
];
