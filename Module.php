<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew;

use Yii;
use yii\base\Module as YiiModule;

/**
 * Модуль новой системы управления модулями.
 *
 * Сам по себе НЕ управляется (это менеджер). Регистрируется в приложении вручную (см. README).
 * DI-проводка живёт в config/container.php и вызывается из {@see Bootstrap}; здесь — страховка на
 * случай, если Bootstrap не добавлен в app bootstrap, чтобы web-контроллёр всё равно работал.
 *
 * Backend-маршруты: `/modmanNew/backend/modules/...` (controllerNamespace по умолчанию
 * `modules\modmanNew\controllers`, путь `backend/modules` резолвится в controllers\backend\ModulesController).
 * Console-маршруты: `modmanNew/modules/...` (controllerNamespace переключается на commands).
 */
final class Module extends YiiModule
{
    public function init(): void
    {
        parent::init();

        if (Yii::$app->id === 'app-console') {
            $this->controllerNamespace = __NAMESPACE__ . '\\commands';
        }

        if (!Yii::$container->has(ModuleManager::class)) {
            (require __DIR__ . '/config/container.php')(Yii::$container);
        }
    }
}
