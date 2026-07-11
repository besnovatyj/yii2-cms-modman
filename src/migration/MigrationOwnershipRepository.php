<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\migration;

use yii\db\Connection;
use yii\db\Query;

/**
 * Учёт владения миграциями: какая миграция применена каким модулем.
 *
 * Это собственная история миграций менеджера (таблица `{{%modman_migration}}`), независимая
 * от глобальной `{{%migration}}`. Решает корневую проблему старого modman, где при удалении
 * откатывались «все миграции, найденные в каталоге и присутствующие в общей истории» — что ломалось
 * при частичном применении, обновлениях и переносе файлов. Здесь откат идёт строго по владельцу.
 */
final class MigrationOwnershipRepository
{
    private const string TABLE = '{{%modman_migration}}';

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Создаёт таблицу истории/владения, если её ещё нет.
     */
    public function ensureSchema(): void
    {
        $rawName = $this->db->schema->getRawTableName(self::TABLE);
        if ($this->db->getTableSchema($rawName, true) !== null) {
            return;
        }

        $this->db->createCommand()->createTable(self::TABLE, [
            'module_id' => 'varchar(128) NOT NULL',
            'version' => 'varchar(180) NOT NULL',
            'namespace' => 'varchar(255) NULL',
            'migration_file' => 'text NULL',
            'apply_time' => 'integer NULL',
            'PRIMARY KEY (module_id, version)',
        ])->execute();
    }

    /**
     * Версии миграций модуля в порядке применения.
     *
     * @return string[]
     */
    public function appliedVersions(string $moduleId): array
    {
        $this->ensureSchema();

        return new Query()
            ->select('version')
            ->from(self::TABLE)
            ->where(['module_id' => $moduleId])
            ->orderBy(['apply_time' => SORT_ASC, 'version' => SORT_ASC])
            ->column($this->db);
    }

    public function isApplied(string $moduleId, string $version): bool
    {
        $this->ensureSchema();

        return new Query()
            ->from(self::TABLE)
            ->where(['module_id' => $moduleId, 'version' => $version])
            ->exists($this->db);
    }

    public function record(string $moduleId, string $version, ?string $namespace, string $file): void
    {
        $this->db->createCommand()->insert(self::TABLE, [
            'module_id' => $moduleId,
            'version' => $version,
            'namespace' => $namespace,
            'migration_file' => $file,
            'apply_time' => time(),
        ])->execute();
    }

    public function forget(string $moduleId, string $version): void
    {
        $this->db->createCommand()->delete(self::TABLE, [
            'module_id' => $moduleId,
            'version' => $version,
        ])->execute();
    }

    public function forgetModule(string $moduleId): void
    {
        $this->db->createCommand()->delete(self::TABLE, ['module_id' => $moduleId])->execute();
    }
}
