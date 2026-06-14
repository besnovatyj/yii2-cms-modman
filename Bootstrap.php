<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew;

use Yii;
use yii\base\BootstrapInterface;

/**
 * Глобальный bootstrap менеджера.
 *
 * Регистрирует DI-проводку (в т.ч. синглтон {@see \modules\modmanNew\events\ModuleLifecycleDispatcher})
 * ДО инициализации остальных модулей — чтобы другие модули могли подписаться на фазы lifecycle в
 * своих Bootstrap. Должен быть добавлен в app bootstrap (см. README).
 */
final class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        (require __DIR__ . '/config/container.php')(Yii::$container);
    }
}
