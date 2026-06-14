<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew;

/**
 * Представление модуля для UI: манифест каталога, наложенный на состояние реестра.
 */
final readonly class ModuleView
{
    public function __construct(
        public string  $id,
        public string  $package,
        public string  $availableVersion,
        public ?string $installedVersion,
        public string  $status,
        public bool    $editable,
        public bool    $installed,
        public bool    $hasUpdate,
        public bool    $orphan = false,
    ) {}
}
