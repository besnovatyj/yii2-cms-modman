<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\Contracts\adminMenu\AdminMenuLocation;
use Besnovatyj\Contracts\adminMenu\AdminMenuPlacement;

/**
 * Вклад менеджера в группу `admin-menu` (формат пункта — {@see AdminMenuPlacement}).
 */
return [[
    'label' => 'Модули',
    'iconClass' => 'bi bi-bricks me-1',
    'url' => ['/Modman/backend/modules/index'],
    'active' => static function (): bool {
        return str_contains(\Yii::$app->request->url, 'Modman/backend/modules');
    },
    '_meta' => [
        'placements' => [
            new AdminMenuPlacement(
                location: AdminMenuLocation::RightSidebar,
                group: 'Service',
                groupIcon: 'bi bi-sliders',
                groupPriority: 100,
                priority: 100,
            ),
        ],
    ],
]];
