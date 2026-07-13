<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman;

/**
 * Представление модуля для UI: манифест каталога, наложенный на состояние реестра.
 *
 * Несёт три «особых» признака помимо обычного статуса: {@see $system} (системный модуль, например
 * сам менеджер — управлять им из админки нельзя, кнопок install/uninstall нет), {@see $orphan}
 * (запись реестра без пакета) и {@see $invalid} (CMS-модуль с ошибкой конфигурации — показывается
 * с причиной и погашенной кнопкой установки). Плюс {@see $warnings} — не-блокирующие предупреждения
 * валидного модуля (цвет warning; invalid/failed — danger), см. {@see catalog\WarningModule}.
 */
final readonly class ModuleView
{
    /**
     * @param string[] $warnings
     */
    public function __construct(
        public string  $id,
        public string  $package,
        public string  $availableVersion,
        public ?string $installedVersion,
        public string  $status,
        public bool    $editable,
        public bool    $installed,
        public bool    $hasUpdate,
        public string  $iconClass = '',
        public bool    $hasOptions = false,
        public bool    $system = false,
        public bool    $orphan = false,
        public bool    $invalid = false,
        public ?string $invalidReason = null,
        public array   $warnings = [],
    ) {}
}
