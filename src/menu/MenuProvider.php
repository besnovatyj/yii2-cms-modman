<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\menu;

use Besnovatyj\Modman\compiler\MenuCompiler;

/**
 * Рантайм-сборка меню из вкладов группы `admin-menu` (yiisoft/config).
 *
 * После перехода на yiisoft/config меню больше не сериализуются в `menu-*.php` (замыкания `active`
 * не переживают var_export). Вместо этого модули объявляют adminMenu через `extra.config-plugin`
 * (группа `admin-menu`), их вклады `require`-ятся с ЖИВЫМИ замыканиями и компилируются здесь по
 * placement/priority ({@see MenuCompiler}) на запросе. Результат мемоизируется на время запроса
 * (синглтон DI), поэтому оба сайдбара компилируют меню один раз.
 */
final class MenuProvider
{
    /** @var array<string, array>|null location => дерево меню (кэш на запрос) */
    private ?array $byLocation = null;

    public function __construct(
        private readonly MenuCompiler $compiler,
    ) {}

    /**
     * Пункты меню для конкретной локации (left-sidebar, right-sidebar, …).
     *
     * @param string $location Локация меню.
     * @param array  $contributions Слитые вклады группы `admin-menu` (список пунктов с `_meta.placements`).
     * @return array Дерево пунктов для локации (ещё не отфильтровано по правам — это делает вызывающий).
     */
    public function forLocation(string $location, array $contributions): array
    {
        $this->byLocation ??= $this->compiler->compile($this->compiler->flatten($contributions));

        return $this->byLocation[$location] ?? [];
    }
}
