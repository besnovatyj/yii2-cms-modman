<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\events;

use Besnovatyj\Modman\catalog\ModuleManifest;

/**
 * Событие жизненного цикла модуля, передаваемое подписчикам.
 */
final readonly class ModuleLifecycleEvent
{
    public function __construct(
        public LifecyclePhase   $phase,
        public string           $moduleId,
        public ?ModuleManifest  $manifest,
    ) {}
}
