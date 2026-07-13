<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog;

/**
 * Валидный CMS-модуль с особенностями, о которых стоит предупредить — БЕЗ блокировки установки.
 *
 * Пара к {@see InvalidModule}: invalid — ошибка конфигурации, модуль непригоден к установке
 * (кнопка погашена); warning — модуль работоспособен, но нарушает конвенцию или несёт неочевидный
 * побочный эффект (например, L1-bootstrap, исполняемый вне гейта менеджера). Показывается строкой
 * рядом с модулем цветом warning (invalid/failed — danger) и попадает в warnings плана операции.
 *
 * Набор проверок расширяем — см. {@see \Besnovatyj\Modman\catalog\check\ModuleWarningCheck}.
 */
final readonly class WarningModule
{
    /**
     * @param string[] $warnings тексты сработавших проверок (минимум один)
     */
    public function __construct(
        public string $package,
        public string $moduleId,
        public array  $warnings,
    ) {}
}
