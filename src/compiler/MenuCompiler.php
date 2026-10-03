<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\compiler;

use Besnovatyj\Contracts\adminMenu\AdminMenuPlacement;
use yii\base\InvalidConfigException;

/**
 * Раскладка пунктов меню админки по локациям: группировка и сортировка по их размещениям.
 *
 * Вход — собранная группа `admin-menu`: плоский список пунктов `NavWidget`, у каждого в
 * `_meta.placements` непустой список {@see AdminMenuPlacement} (формат пункта описан там же). Выход —
 * дерево для `NavWidget` по каждой локации, в которую встал хотя бы один пункт; `_meta` срезается.
 *
 * Порядок: группы — по `groupPriority`, затем по заголовку; пункты внутри группы — по `priority`, затем
 * по `label`. Пункты без группы сортируются среди групп одним блоком — как группа без заголовка.
 *
 * Чистая функция: без `Yii::$app`, файлов и побочных эффектов.
 */
final class MenuCompiler
{
    /** Ключ блока пунктов без группы внутри локации (не пересекается с заголовками групп). */
    private const string UNGROUPED = "\0ungrouped";

    /**
     * @param array<int, array> $items плоский список пунктов группы `admin-menu`
     * @return array<string, array<int, array>> значение {@see \Besnovatyj\Contracts\adminMenu\AdminMenuLocation} => дерево меню
     * @throws InvalidConfigException у пункта нет размещений или размещение не {@see AdminMenuPlacement}
     */
    public function compile(array $items): array
    {
        $byLocation = [];
        foreach ($this->groupByLocation($items) as $location => $groups) {
            $byLocation[$location] = $this->buildLocationMenu($groups);
        }

        return $byLocation;
    }

    /**
     * @param array<int, array> $items
     * @return array<string, array<string, array{placement: AdminMenuPlacement, entries: array<int, array{placement: AdminMenuPlacement, item: array}>}>>
     *         локация => ключ группы => группа (открывшее её размещение + пункты)
     * @throws InvalidConfigException
     */
    private function groupByLocation(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $menuItem = $item;
            unset($menuItem['_meta']);

            foreach ($this->placementsOf($item) as $placement) {
                $location = $placement->location->value;
                $groupKey = $placement->group ?? self::UNGROUPED;

                $grouped[$location][$groupKey] ??= ['placement' => $placement, 'entries' => []];
                $grouped[$location][$groupKey]['entries'][] = ['placement' => $placement, 'item' => $menuItem];
            }
        }

        return $grouped;
    }

    /**
     * @return AdminMenuPlacement[]
     * @throws InvalidConfigException
     */
    private function placementsOf(array $item): array
    {
        $label = (string)($item['label'] ?? '?');
        $placements = $item['_meta']['placements'] ?? [];

        if (!is_array($placements) || $placements === []) {
            throw new InvalidConfigException("Пункт меню админки '{$label}': не задан `_meta.placements`.");
        }
        foreach ($placements as $placement) {
            if (!$placement instanceof AdminMenuPlacement) {
                throw new InvalidConfigException(
                    "Пункт меню админки '{$label}': размещение должно быть " . AdminMenuPlacement::class . '.'
                );
            }
        }

        return $placements;
    }

    /**
     * @param array<string, array{placement: AdminMenuPlacement, entries: array<int, array{placement: AdminMenuPlacement, item: array}>}> $groups
     * @return array<int, array>
     */
    private function buildLocationMenu(array $groups): array
    {
        uasort($groups, static fn(array $a, array $b): int
            => [$a['placement']->groupPriority, (string)$a['placement']->group]
                <=> [$b['placement']->groupPriority, (string)$b['placement']->group]);

        $menu = [];
        foreach ($groups as $groupKey => $group) {
            $entries = $group['entries'];
            usort($entries, static fn(array $a, array $b): int
                => [$a['placement']->priority, (string)($a['item']['label'] ?? '')]
                    <=> [$b['placement']->priority, (string)($b['item']['label'] ?? '')]);
            $items = array_column($entries, 'item');

            if ($groupKey === self::UNGROUPED) {
                array_push($menu, ...$items);
            } else {
                $menu[] = [
                    'label' => $group['placement']->group,
                    'iconClass' => $group['placement']->groupIcon,
                    'items' => $items,
                ];
            }
        }

        return $menu;
    }
}
