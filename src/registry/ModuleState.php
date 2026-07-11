<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\registry;

/**
 * Запись о модуле в реестре — единица единого источника истины.
 *
 * Неизменяема: переходы состояния создают новый объект через `with*`-методы. Это делает реестр
 * предсказуемым и упрощает commit-at-end (исполнитель формирует целевое состояние, затем атомарно
 * сохраняет).
 */
final readonly class ModuleState
{
    /**
     * @param string[] $appliedMigrations версии миграций, применённые этим модулем
     */
    public function __construct(
        public string       $id,
        public string       $package,
        public string       $moduleClass,
        public Version      $version,
        public ModuleStatus $status,
        public string       $operationId,
        public array        $appliedMigrations,
        public string       $manifestChecksum,
        public int          $installedAt,
        public int          $updatedAt,
    ) {}

    public function withStatus(ModuleStatus $status, ?string $operationId = null): self
    {
        return new self(
            $this->id, $this->package, $this->moduleClass, $this->version, $status,
            $operationId ?? $this->operationId, $this->appliedMigrations, $this->manifestChecksum,
            $this->installedAt, time(),
        );
    }

    /**
     * @param string[] $versions
     */
    public function withAppliedMigrations(array $versions): self
    {
        return new self(
            $this->id, $this->package, $this->moduleClass, $this->version, $this->status,
            $this->operationId, array_values($versions), $this->manifestChecksum,
            $this->installedAt, time(),
        );
    }

    public function withVersion(Version $version): self
    {
        return new self(
            $this->id, $this->package, $this->moduleClass, $version, $this->status,
            $this->operationId, $this->appliedMigrations, $this->manifestChecksum,
            $this->installedAt, time(),
        );
    }

    public function withChecksum(string $checksum): self
    {
        return new self(
            $this->id, $this->package, $this->moduleClass, $this->version, $this->status,
            $this->operationId, $this->appliedMigrations, $checksum,
            $this->installedAt, time(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'package' => $this->package,
            'moduleClass' => $this->moduleClass,
            'version' => $this->version->value,
            'status' => $this->status->value,
            'operationId' => $this->operationId,
            'appliedMigrations' => $this->appliedMigrations,
            'manifestChecksum' => $this->manifestChecksum,
            'installedAt' => $this->installedAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string)$data['id'],
            package: (string)($data['package'] ?? ''),
            moduleClass: (string)($data['moduleClass'] ?? ''),
            version: new Version((string)($data['version'] ?? '0.0.0')),
            status: ModuleStatus::from((string)($data['status'] ?? ModuleStatus::Installed->value)),
            operationId: (string)($data['operationId'] ?? ''),
            appliedMigrations: array_values((array)($data['appliedMigrations'] ?? [])),
            manifestChecksum: (string)($data['manifestChecksum'] ?? ''),
            installedAt: (int)($data['installedAt'] ?? 0),
            updatedAt: (int)($data['updatedAt'] ?? 0),
        );
    }
}
