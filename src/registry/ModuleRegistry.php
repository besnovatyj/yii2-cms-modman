<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\registry;

use modules\modman\compiler\AtomicWriter;

/**
 * Единый изменяемый источник истины о состоянии модулей — атомарный lock-файл.
 *
 * Почему файл, а не БД: список и состояние модулей нужны на самом раннем бутстрапе (до создания
 * приложения и его компонентов, включая `db`). Файл читается дёшево и без зависимостей. БД может
 * служить зеркалом для истории, но истина — здесь.
 *
 * Все производные конфиги ({@see \modules\modman\compiler\ConfigCompiler}) собираются из этого
 * реестра. Запись — целиком и атомарно ({@see AtomicWriter}); под мьютексом lifecycle конкуренция
 * исключена.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleState>|null */
    private ?array $states = null;

    public function __construct(
        private readonly string       $lockFile,
        private readonly AtomicWriter $writer,
    ) {}

    /**
     * @return array<string, ModuleState> id => состояние, отсортировано по id
     */
    public function all(): array
    {
        $this->load();
        return $this->states;
    }

    public function get(string $id): ?ModuleState
    {
        $this->load();
        return $this->states[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Установлен и согласован (status = installed). В отличие от старого modman НЕ равно
     * «прописан в конфиге».
     */
    public function isInstalled(string $id): bool
    {
        return $this->get($id)?->status->isActive() ?? false;
    }

    /**
     * Модули с незавершённой операцией (транзиентный/failed статус) — кандидаты на reconcile.
     * @return array<string, ModuleState>
     */
    public function pendingStates(): array
    {
        return array_filter(
            $this->all(),
            static fn(ModuleState $s): bool => $s->status->isTransient() || $s->status === ModuleStatus::Failed,
        );
    }

    /**
     * Upsert состояния и атомарное сохранение реестра.
     */
    public function save(ModuleState $state): void
    {
        $this->load();
        $this->states[$state->id] = $state;
        $this->persist();
    }

    /**
     * Удаление состояния и атомарное сохранение реестра.
     */
    public function remove(string $id): void
    {
        $this->load();
        unset($this->states[$id]);
        $this->persist();
    }

    private function load(): void
    {
        if ($this->states !== null) {
            return;
        }

        $states = [];
        if (is_file($this->lockFile)) {
            /** @var array<string, array> $raw */
            $raw = require $this->lockFile;
            if (is_array($raw)) {
                foreach ($raw as $id => $stateData) {
                    if (is_array($stateData)) {
                        $states[(string)$id] = ModuleState::fromArray($stateData);
                    }
                }
            }
        }

        ksort($states);
        $this->states = $states;
    }

    private function persist(): void
    {
        ksort($this->states);
        $data = array_map(static fn(ModuleState $s): array => $s->toArray(), $this->states);
        $this->writer->writeArray($this->lockFile, $data);
    }
}
