<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\deps\exception;

use RuntimeException;

/**
 * Нарушение зависимостей (отсутствует модуль/расширение, не та версия, есть обратные зависимости).
 */
class DependencyException extends RuntimeException
{
}
