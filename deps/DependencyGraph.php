<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\deps;

use modules\modmanNew\deps\exception\CircularDependencyException;

/**
 * Ориентированный граф зависимостей модулей с топологической сортировкой.
 *
 * Чистая структура данных: узлы — id модулей, рёбра — «зависит от». Используется для определения
 * порядка пакетной установки и обнаружения циклов.
 */
final class DependencyGraph
{
    /**
     * @param array<string, string[]> $edges node => список id, от которых node зависит
     */
    public function __construct(
        private readonly array $edges,
    ) {}

    /**
     * Топологический порядок: зависимости раньше зависящих.
     *
     * @return string[]
     * @throws CircularDependencyException
     */
    public function topologicalOrder(): array
    {
        $sorted = [];
        $visited = [];   // node => true (полностью обработан)
        $onStack = [];   // node => true (в текущей ветке DFS)

        foreach (array_keys($this->edges) as $node) {
            $this->visit((string)$node, $visited, $onStack, $sorted);
        }

        return $sorted;
    }

    /**
     * @param array<string,bool> $visited
     * @param array<string,bool> $onStack
     * @param string[]           $sorted
     */
    private function visit(string $node, array &$visited, array &$onStack, array &$sorted): void
    {
        if (isset($visited[$node])) {
            return;
        }
        $onStack[$node] = true;

        foreach ($this->edges[$node] ?? [] as $dep) {
            if (isset($onStack[$dep])) {
                throw new CircularDependencyException("Циклическая зависимость: {$node} → {$dep}.");
            }
            if (isset($this->edges[$dep])) {
                $this->visit($dep, $visited, $onStack, $sorted);
            }
        }

        unset($onStack[$node]);
        $visited[$node] = true;
        $sorted[] = $node;
    }
}
