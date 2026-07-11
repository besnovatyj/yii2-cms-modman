<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\deps\exception;

/**
 * Обнаружена циклическая зависимость между модулями.
 */
class CircularDependencyException extends DependencyException
{
}
