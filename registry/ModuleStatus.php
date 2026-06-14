<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\registry;

/**
 * Статус модуля в реестре — status-машина жизненного цикла.
 *
 * Разводит два состояния, которые в старом modman были слиты в `isInstalled() === hasModule()`:
 * «модуль прописан в конфиге» и «модуль реально применён (миграции/директории)». Транзиентные
 * статусы (installing/updating/removing) позволяют обнаружить незавершённую операцию после краша
 * и предложить восстановление ({@see \modules\modmanNew\lifecycle\handler\ReconcileHandler}).
 */
enum ModuleStatus: string
{
    /** Обнаружен в каталоге, но не установлен. */
    case Discovered = 'discovered';
    /** Идёт установка (транзиентный). */
    case Installing = 'installing';
    /** Установлен и согласован. */
    case Installed = 'installed';
    /** Идёт обновление (транзиентный). */
    case Updating = 'updating';
    /** Идёт удаление (транзиентный). */
    case Removing = 'removing';
    /** Операция прервана — состояние требует сверки. */
    case Failed = 'failed';

    /**
     * Транзиентный статус = операция была начата, но не подтверждена. Кандидат на reconcile.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::Installing, self::Updating, self::Removing => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return $this === self::Installed;
    }
}
