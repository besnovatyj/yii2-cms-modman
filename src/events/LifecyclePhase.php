<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\events;

/**
 * Фаза жизненного цикла, публикуемая в {@see ModuleLifecycleDispatcher}.
 *
 * На эти фазы могут подписываться ДРУГИЕ модули (через свой Bootstrap), реализуя межмодульные
 * интеграции. В старом modman события висели на выбрасываемом экземпляре модуля, поэтому сторонние
 * подписки были невозможны.
 */
enum LifecyclePhase: string
{
    case BeforeInstall = 'beforeInstall';
    case AfterInstall = 'afterInstall';
    case BeforeUninstall = 'beforeUninstall';
    case AfterUninstall = 'afterUninstall';
    case BeforeUpdate = 'beforeUpdate';
    case AfterUpdate = 'afterUpdate';
}
