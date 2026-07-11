<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\compiler;

/**
 * Пути производных артефактов, которые компилятор ещё генерирует (см. {@see ConfigCompiler}).
 *
 * modules/components/bootstrap/appConfig/menu после переезда на yiisoft/config собираются движком
 * по merge-plan и здесь не фигурируют.
 */
final readonly class ArtifactPaths
{
    public function __construct(
        public string $logChannelsConfig,
        public string $optionsConfig,
        public string $viewSourcesConfig,
    ) {}

    /**
     * Все пути артефактов (для диагностики/очистки).
     * @return string[]
     */
    public function all(): array
    {
        return [$this->logChannelsConfig, $this->optionsConfig, $this->viewSourcesConfig];
    }
}
