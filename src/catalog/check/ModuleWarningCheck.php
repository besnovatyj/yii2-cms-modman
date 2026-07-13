<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog\check;

use Besnovatyj\Modman\catalog\ModuleManifest;
use Besnovatyj\Modman\catalog\source\DiscoveredPackage;

/**
 * Одна не-блокирующая проверка модуля для {@see \Besnovatyj\Modman\catalog\WarningModule}.
 *
 * Контракт расширяемости: набор проверок задаётся списком в DI-проводке каталога
 * ({@see \Besnovatyj\Modman\catalog\PackageCatalog}), новая проверка — новый класс, без правок
 * каталога. Проверки выполняются на этапе discovery над УЖЕ валидным манифестом (ошибки
 * конфигурации, делающие модуль непригодным, — зона {@see \Besnovatyj\Modman\catalog\InvalidModule}).
 * Проверка обязана быть дешёвой и статической: никакого инстанцирования модуля/IO.
 */
interface ModuleWarningCheck
{
    /**
     * @return string|null текст предупреждения или null, если проверка пройдена
     */
    public function check(DiscoveredPackage $package, ModuleManifest $manifest): ?string;
}
