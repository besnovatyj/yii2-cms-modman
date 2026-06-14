<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\events;

use Throwable;
use Yii;

/**
 * Шина событий жизненного цикла уровня приложения.
 *
 * DI-синглтон: модули подписываются в своём `Bootstrap.php` (который грузится всегда), а lifecycle
 * публикует фазы. Ошибка одного слушателя не срывает операцию — логируется и не пробрасывается
 * (подписчики не должны ломать установку чужого модуля).
 */
final class ModuleLifecycleDispatcher
{
    /** @var array<string, list<callable(ModuleLifecycleEvent):void>> */
    private array $listeners = [];

    public function on(LifecyclePhase $phase, callable $listener): void
    {
        $this->listeners[$phase->value][] = $listener;
    }

    public function dispatch(ModuleLifecycleEvent $event): void
    {
        foreach ($this->listeners[$event->phase->value] ?? [] as $listener) {
            try {
                $listener($event);
            } catch (Throwable $e) {
                Yii::error(
                    "Слушатель фазы {$event->phase->value} (модуль {$event->moduleId}) упал: {$e->getMessage()}",
                    'modmanNew/events',
                );
            }
        }
    }
}
