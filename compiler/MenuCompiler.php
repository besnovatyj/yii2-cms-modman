<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\compiler;

/**
 * Чистая сборка меню админки из вкладов модулей.
 *
 * Перенос логики старого `AdminMenuManageService`, но как ДЕТЕРМИНИРОВАННАЯ функция от входных
 * пунктов меню: никаких `Yii::$app->modules`, файлов и побочных эффектов. На вход — «сырые» пункты
 * (из манифестов), на выходе — карта `location => дерево меню`. Запись в файлы — ответственность
 * {@see ConfigCompiler}.
 *
 * Поддерживается новый формат с `_meta.placements` и легаси-формат (группа с `items`).
 */
final class MenuCompiler
{
    /**
     * @param string[]            $knownLocations известные locations (для инициализации)
     * @param array<string,mixed> $defaults       defaultPriority/defaultGroupPriority
     */
    public function __construct(
        private readonly array $knownLocations,
        private readonly array $defaults = ['defaultPriority' => 500, 'defaultGroupPriority' => 500],
    ) {}

    /**
     * @param array<int, array> $rawMenuItems плоский список пунктов меню из всех модулей
     * @return array<string, array> location => дерево меню
     */
    public function compile(array $rawMenuItems): array
    {
        $byLocation = [];
        foreach ($this->knownLocations as $location) {
            $byLocation[$location] = [];
        }

        $grouped = $this->groupItemsByLocationAndGroup($rawMenuItems);
        foreach ($grouped as $location => $groups) {
            $byLocation[$location] = $this->buildLocationMenu($groups);
        }

        return $byLocation;
    }

    /**
     * Раскладывает плоские пункты модулей в плоский список (с поддержкой одиночного пункта).
     * @param array<int, array> $contributions каждый элемент — результат ProvidesAdminMenu::adminMenu()
     * @return array<int, array>
     */
    public function flatten(array $contributions): array
    {
        $items = [];
        foreach ($contributions as $menu) {
            if ($menu === []) {
                continue;
            }
            if (isset($menu['label'])) {
                $items[] = $menu; // одиночный пункт
            } else {
                foreach ($menu as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
            }
        }
        return $items;
    }

    /**
     * @param array<int, array> $rawMenuItems
     * @return array<string, array<string, array>>
     */
    private function groupItemsByLocationAndGroup(array $rawMenuItems): array
    {
        $grouped = [];

        foreach ($rawMenuItems as $item) {
            // Легаси-формат: группа с вложенными items без _meta.
            if (isset($item['items']) && is_array($item['items']) && !isset($item['_meta']['placements'])) {
                $this->processLegacyGroup($item, $grouped);
                continue;
            }

            $placements = $item['_meta']['placements'] ?? [];
            foreach ($placements as $placement) {
                $location = $placement['location'] ?? 'left-sidebar';
                $group = $placement['group'] ?? null;
                $groupKey = $group ?? '__NO_GROUP__';

                $grouped[$location][$groupKey] ??= [
                    'items' => [],
                    'groupPriority' => $placement['groupPriority'] ?? $this->defaults['defaultGroupPriority'],
                    'groupIcon' => $placement['groupIcon'] ?? null,
                    'groupLabel' => $group,
                ];

                $menuItem = $this->stripMetadata($item);
                $menuItem['_priority'] = $placement['priority'] ?? $this->defaults['defaultPriority'];
                $grouped[$location][$groupKey]['items'][] = $menuItem;
            }
        }

        return $grouped;
    }

    /**
     * @param array<string, array<string, array>> $grouped
     */
    private function processLegacyGroup(array $item, array &$grouped): void
    {
        $location = 'left-sidebar';
        $groupLabel = $item['label'];

        $grouped[$location][$groupLabel] ??= [
            'items' => [],
            'groupPriority' => $this->defaults['defaultGroupPriority'],
            'groupIcon' => $item['iconClass'] ?? 'bi bi-folder',
            'groupLabel' => $groupLabel,
        ];

        foreach ($item['items'] as $subItem) {
            $subItem['_priority'] = $this->defaults['defaultPriority'];
            $grouped[$location][$groupLabel]['items'][] = $subItem;
        }
    }

    /**
     * @param array<string, array> $groups
     * @return array<int, array>
     */
    private function buildLocationMenu(array $groups): array
    {
        uasort($groups, function (array $a, array $b): int {
            $pa = $a['groupPriority'] ?? 500;
            $pb = $b['groupPriority'] ?? 500;
            return $pa !== $pb ? $pa <=> $pb : strcmp((string)($a['groupLabel'] ?? ''), (string)($b['groupLabel'] ?? ''));
        });

        $menu = [];
        foreach ($groups as $groupKey => $groupData) {
            $items = $groupData['items'];
            usort($items, function (array $a, array $b): int {
                $pa = $a['_priority'] ?? 500;
                $pb = $b['_priority'] ?? 500;
                return $pa !== $pb ? $pa <=> $pb : strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
            });

            foreach ($items as &$item) {
                unset($item['_priority']);
            }
            unset($item);

            if ($groupKey === '__NO_GROUP__') {
                $menu = array_merge($menu, $items);
            } else {
                $menu[] = [
                    'label' => $groupData['groupLabel'],
                    'iconClass' => $groupData['groupIcon'] ?? 'bi bi-folder',
                    'items' => $items,
                ];
            }
        }

        return $menu;
    }

    private function stripMetadata(array $item): array
    {
        unset($item['_meta'], $item['_placements'], $item['_module_group'], $item['id']);
        return $item;
    }
}
