<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle\exception;

use RuntimeException;

/**
 * Ошибка выполнения операции жизненного цикла (в т.ч. невозможность взять блокировку).
 */
class LifecycleException extends RuntimeException
{
}
