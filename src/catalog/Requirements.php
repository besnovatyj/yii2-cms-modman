<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog;

/**
 * Типизированные требования модуля (зависимости).
 *
 * Заменяет «голый массив» старого modman. Собирается {@see ManifestFactory} из результата
 * {@see \Besnovatyj\Contracts\module\ProvidesDependencies::dependencies()}.
 */
final readonly class Requirements
{
    /**
     * @param string[] $modules        id зависимого модуля или 'id:constraint'
     * @param string[] $phpExtensions  имена PHP-расширений
     */
    public function __construct(
        public array   $modules = [],
        public array   $phpExtensions = [],
        public ?string $phpVersion = null,
        public ?string $yiiVersion = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            modules: array_values(array_filter((array)($data['modules'] ?? []), 'is_string')),
            phpExtensions: array_values(array_filter((array)($data['php_extensions'] ?? []), 'is_string')),
            phpVersion: isset($data['php_version']) ? (string)$data['php_version'] : null,
            yiiVersion: isset($data['yii_version']) ? (string)$data['yii_version'] : null,
        );
    }

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->modules === []
            && $this->phpExtensions === []
            && $this->phpVersion === null
            && $this->yiiVersion === null;
    }

    public function toArray(): array
    {
        return [
            'modules' => $this->modules,
            'php_extensions' => $this->phpExtensions,
            'php_version' => $this->phpVersion,
            'yii_version' => $this->yiiVersion,
        ];
    }
}
