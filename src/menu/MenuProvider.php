<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\menu;

use Besnovatyj\Contracts\adminMenu\AdminMenuLocation;
use Besnovatyj\Modman\compiler\MenuCompiler;
use yii\base\InvalidConfigException;

/**
 * Меню админки по локациям для текущего запроса.
 *
 * Пункты — собранная группа `admin-menu` (формат пункта — {@see \Besnovatyj\Contracts\adminMenu\AdminMenuPlacement},
 * правила раскладки — {@see MenuCompiler}). Раскладка
 * выполняется один раз и мемоизируется на запрос (синглтон DI): сайдбары, шапка и палитра команд
 * читают одно и то же разложенное меню.
 */
final class MenuProvider
{
    /** @var array<string, array>|null location => дерево меню (кэш на запрос) */
    private ?array $byLocation = null;

    public function __construct(
        private readonly MenuCompiler $compiler,
    ) {}

    /**
     * Дерево пунктов одной локации.
     *
     * Пункты раскладываются при ПЕРВОМ вызове за запрос; все вызывающие передают одну и ту же группу
     * `admin-menu`, поэтому `$items` последующих вызовов не перечитываются.
     *
     * @param AdminMenuLocation $location Локация.
     * @param array             $items    Собранная группа `admin-menu` (плоский список пунктов с `_meta.placements`).
     * @return array Дерево для NavWidget (ещё не отфильтровано по правам — это делает вызывающий);
     *               пустой массив, если в локацию не встал ни один пункт.
     * @throws InvalidConfigException пункт меню без корректных размещений
     */
    public function forLocation(AdminMenuLocation $location, array $items): array
    {
        $this->byLocation ??= $this->compiler->compile($items);

        return $this->byLocation[$location->value] ?? [];
    }
}
