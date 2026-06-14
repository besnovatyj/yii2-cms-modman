<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew;

use common\components\module\CmsModule;
use modules\modmanNew\contract\DeclaresModule;
use modules\modmanNew\contract\ProvidesAdminMenu;
use modules\modmanNew\contract\ProvidesLogChannels;
use Yii;

/**
 * Модуль новой системы управления модулями.
 *
 * Намеренно НЕ выбивается из общей кучи: это такой же модуль, как и управляемые им, и реализует тот
 * же контракт {@see DeclaresModule}. Отличие — {@see isEditable()} === false: системный модуль,
 * который ставится установочным скриптом CMS (флаг editable ему безразличен), а из админки остаётся
 * «неприкасаемым». Поэтому он виден в общем списке как «системный/активный» (с версией, без кнопок
 * установки/удаления), а не как нарушитель контракта.
 *
 * Бутстрап проводки выполняется приложением вручную через {@see Bootstrap} ДО существования
 * собственного реестра — иначе некому скомпилировать конфиги. Здесь — страховка на случай, если
 * Bootstrap не добавлен в app bootstrap, чтобы web-контроллёр всё равно работал.
 *
 * Backend-маршруты: `/modmanNew/backend/modules/...` (controllerNamespace по умолчанию
 * `modules\modmanNew\controllers`, путь `backend/modules` резолвится в controllers\backend\ModulesController).
 * Console-маршруты: `modmanNew/modules/...` (controllerNamespace переключается на commands).
 */
final class Module extends CmsModule implements DeclaresModule, ProvidesAdminMenu, ProvidesLogChannels
{
    /** Версия менеджера — источник истины для отображения и будущего самообновления. */
    public const string VERSION = '1.0.0';

    public function init(): void
    {
        // Раскладку controllerNamespace (в т.ч. console → \commands) и layout даёт CmsModule.
        parent::init();

        // Страховка DI на случай, если Bootstrap не добавлен в app bootstrap, — чтобы web-контроллёр
        // всё равно работал. Основная проводка живёт в Bootstrap; здесь guard от повторного прогона.
        if (!Yii::$container->has(ModuleManager::class)) {
            (require __DIR__ . '/config/container.php')(Yii::$container);
        }
    }

    public static function moduleId(): string
    {
        return 'modmanNew';
    }

    public static function moduleVersion(): string
    {
        return self::VERSION;
    }

    public static function moduleConfig(): array
    {
        return require __DIR__ . '/config/config.php';
    }

    /**
     * Системный модуль: управлять им из админки нельзя. Установочный скрипт CMS флаг игнорирует.
     */
    public static function isEditable(): bool
    {
        return false;
    }

    public static function adminMenu(): array
    {
        return require __DIR__ . '/config/adminMenu.php';
    }

    public static function logChannels(): array
    {
        return require __DIR__ . '/config/log.php';
    }
}
