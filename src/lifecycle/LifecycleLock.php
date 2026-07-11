<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle;

use modules\modman\lifecycle\exception\LifecycleException;
use Throwable;
use yii\mutex\Mutex;

/**
 * Системная блокировка lifecycle-операций.
 *
 * Закрывает гонку из старого modman: два параллельных запроса не смогут одновременно менять реестр и
 * перекомпилировать артефакты. На одном prod-сервере {@see \yii\mutex\FileMutex} достаточно.
 */
final class LifecycleLock
{
    public function __construct(
        private readonly Mutex  $mutex,
        private readonly string $name = 'modman.lifecycle',
        private readonly int    $timeout = 15,
    ) {}

    /**
     * Выполнить $fn под эксклюзивной блокировкой.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     * @throws LifecycleException если блокировку не удалось получить
     */
    public function withLock(callable $fn): mixed
    {
        if (!$this->mutex->acquire($this->name, $this->timeout)) {
            throw new LifecycleException(
                'Не удалось получить блокировку lifecycle — вероятно, выполняется другая операция с модулями.'
            );
        }

        try {
            return $fn();
        } finally {
            $this->mutex->release($this->name);
        }
    }
}
