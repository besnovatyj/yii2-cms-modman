<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\catalog\source;

/**
 * Вид пакета CMS — объявляется явно в `extra.bescms.kind` (а не выводится из наличия moduleClass).
 *
 * Явность вместо магии: пакет сам декларирует, претендует ли он на управление менеджером
 * ({@see self::Module}) или это просто часть CMS без жизненного цикла ({@see self::Package}).
 */
enum CmsKind: string
{
    /** Полноценный управляемый модуль (есть extra.moduleClass/moduleId, контракт DeclaresModule). */
    case Module = 'module';

    /** Пакет CMS без жизненного цикла (виден в списке пакетов, но не устанавливается менеджером). */
    case Package = 'package';
}
