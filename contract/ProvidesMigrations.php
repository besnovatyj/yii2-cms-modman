<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\contract;

/**
 * Модуль предоставляет миграции БД.
 *
 * В отличие от старого modman, путь и namespace объявляются явно (не угадываются парсингом PHP
 * построчно). Применённые миграции фиксируются за модулем
 * через {@see \modules\modman\migration\MigrationOwnershipRepository}, что делает корректными
 * частичные применения, обновления и перенос файлов.
 */
interface ProvidesMigrations
{
    /**
     * Абсолютный путь (или alias) к директории миграций.
     */
    public static function migrationPath(): string;

    /**
     * Namespace миграций (если миграции namespaced) или null.
     */
    public static function migrationNamespace(): ?string;
}
