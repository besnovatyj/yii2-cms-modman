<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle;

/**
 * Тип операции жизненного цикла модуля.
 */
enum OperationType: string
{
    case Check = 'check';
    case Install = 'install';
    case Uninstall = 'uninstall';
    case Update = 'update';
    case Reconcile = 'reconcile';
    case Sync = 'sync';
}
