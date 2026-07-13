<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman;

use Besnovatyj\Kernel\module\CmsModule;
use Besnovatyj\Contracts\module\DeclaresModule;
use Besnovatyj\Contracts\module\ProvidesAdminMenu;
use Besnovatyj\Contracts\module\ProvidesLogChannels;
use Besnovatyj\Contracts\module\ProvidesOptions;
use Yii;

/**
 * Модуль системы управления модулями.
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
 * Backend-маршруты: `/Modman/backend/modules/...` (controllerNamespace по умолчанию
 * `Besnovatyj\Modman\controllers`, путь `backend/modules` резолвится в controllers\backend\ModulesController).
 * Console-маршруты: `Modman/modules/...` (controllerNamespace переключается на commands).
 */
final class Module extends CmsModule implements DeclaresModule, ProvidesAdminMenu, ProvidesLogChannels, ProvidesOptions
{
    /** Версия менеджера — источник истины для отображения и будущего самообновления. */
    public const string VERSION = '1.0.0';

    /**
     * Проводка менеджера глобальная — грузится через {@see Bootstrap} (способ B) ещё до существования
     * реестра. Поэтому здесь (хук способа A из {@see CmsModule}) лишь страховка, если Bootstrap не
     * добавлен в app bootstrap, причём с guard'ом — чтобы не прогонять тяжёлый граф DI повторно.
     */
    protected function bootstrapContainer(): void
    {
        if (!Yii::$container->has(ModuleManager::class)) {
            parent::bootstrapContainer();
        }
    }

    public static function moduleId(): string
    {
        return 'Modman';
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

    /**
     * Опции менеджера (модуль конфигурации `yii2-cms-config`). Пока единственная — GitHub-токен для
     * проверки upstream-версий: без него лимит анонимных запросов 60/час, с ним 5000/час.
     * Опция необязательна: не задан токен — работает режим «проверка по кнопке».
     */
    public static function options(): array
    {
        return require __DIR__ . '/config/options.php';
    }
}
