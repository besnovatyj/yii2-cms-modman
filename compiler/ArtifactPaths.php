<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\compiler;

/**
 * Пути всех производных артефактов конфигурации.
 */
final readonly class ArtifactPaths
{
    /**
     * @param array<string, string> $menuLocationFiles location => абсолютный путь файла (только включённые)
     */
    public function __construct(
        public string $modulesConfig,
        public string $bootstrapConfig,
        public string $componentsConfig,
        public string $logChannelsConfig,
        public string $optionsConfig,
        public array  $menuLocationFiles,
    ) {}

    /**
     * Все пути артефактов (для диагностики/очистки).
     * @return string[]
     */
    public function all(): array
    {
        return array_merge(
            [$this->modulesConfig, $this->bootstrapConfig, $this->componentsConfig, $this->logChannelsConfig, $this->optionsConfig],
            array_values($this->menuLocationFiles),
        );
    }
}
