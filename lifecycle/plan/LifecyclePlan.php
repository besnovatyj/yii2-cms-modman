<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle\plan;

use modules\modmanNew\lifecycle\OperationType;

/**
 * План операции — чистый, ничего не меняющий объект.
 *
 * Dry-run возвращает именно его (в отличие от старого modman, где dry-run был `json_encode` во flash).
 * `blockers` — причины, по которым операция невыполнима; `steps` — что будет сделано.
 */
final readonly class LifecyclePlan
{
    /**
     * @param PlannedStep[] $steps
     * @param string[]      $blockers
     * @param string[]      $warnings
     */
    public function __construct(
        public OperationType $type,
        public string        $moduleId,
        public string        $version,
        public array         $steps,
        public array         $blockers,
        public array         $warnings,
    ) {}

    public function isFeasible(): bool
    {
        return $this->blockers === [];
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'moduleId' => $this->moduleId,
            'version' => $this->version,
            'feasible' => $this->isFeasible(),
            'steps' => array_map(static fn(PlannedStep $s): array => $s->toArray(), $this->steps),
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
        ];
    }
}
