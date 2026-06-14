<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle\plan;

/**
 * Один шаг плана операции (для отображения dry-run/предпросмотра).
 */
final readonly class PlannedStep
{
    public function __construct(
        public string $title,
        public string $detail = '',
    ) {}

    public function toArray(): array
    {
        return ['title' => $this->title, 'detail' => $this->detail];
    }
}
