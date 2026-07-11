<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog\source;

/**
 * Источник обнаружения пакетов.
 *
 * Полиморфизм discovery — то, чего не хватало старому modman (жёстко зашитый скан директорий).
 * Реализации: {@see FilesystemModuleSource} (dev/скан директорий), {@see ComposerInstalledModuleSource}
 * (прод/реестр composer). Источник НИКОГДА не грузит классы модулей — только читает composer.json.
 */
interface ModuleSource
{
    /**
     * @return DiscoveredPackage[]
     */
    public function discover(): array;

    /**
     * Человекочитаемая метка источника (для предупреждений/диагностики).
     */
    public function label(): string;

    /**
     * Предупреждения последнего вызова {@see discover()} (битый composer.json и т.п.).
     * @return string[]
     */
    public function warnings(): array;
}
