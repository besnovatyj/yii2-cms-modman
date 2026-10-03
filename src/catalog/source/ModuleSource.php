<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog\source;

/**
 * Источник обнаружения пакетов.
 *
 * Модули попадают в систему только через composer, поэтому реализация одна —
 * {@see ComposerInstalledModuleSource}. Абстракция отделяет каталог от формата реестра composer.
 * Источник НИКОГДА не грузит классы модулей — только читает метаданные пакетов.
 */
interface ModuleSource
{
    /**
     * @return DiscoveredPackage[]
     */
    public function discover(): array;

    /**
     * Предупреждения последнего вызова {@see discover()} (битый composer.json и т.п.).
     * @return string[]
     */
    public function warnings(): array;
}
