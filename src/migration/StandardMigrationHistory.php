<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\migration;

use yii\db\Connection;
use yii\db\Query;

/**
 * Штатная история миграций Yii (`{{%migration}}`).
 *
 * Менеджер ведёт собственный учёт владения ({@see MigrationOwnershipRepository}), но параллельно
 * синхронизирует и стандартную таблицу Yii, чтобы:
 *  - миграции, накатанные обычным `yii migrate`, не применялись менеджером повторно;
 *  - миграции, накатанные менеджером, были видны обычному `yii migrate` (и не накатывались им снова).
 *
 * Формат `version` совпадает с тем, что использует Yii и {@see ModuleMigrationRunner::scan()}
 * (FQCN для namespaced-миграций, иначе короткое имя класса), поэтому записи взаимно согласованы.
 */
final class StandardMigrationHistory
{
    private const string TABLE = '{{%migration}}';

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Создаёт штатную таблицу истории, если её ещё нет (схема как у MigrateController).
     */
    public function ensureSchema(): void
    {
        $rawName = $this->db->schema->getRawTableName(self::TABLE);
        if ($this->db->getTableSchema($rawName, true) !== null) {
            return;
        }

        $this->db->createCommand()->createTable(self::TABLE, [
            'version' => 'varchar(180) NOT NULL PRIMARY KEY',
            'apply_time' => 'integer',
        ])->execute();
    }

    public function isApplied(string $version): bool
    {
        $this->ensureSchema();

        return new Query()
            ->from(self::TABLE)
            ->where(['version' => $version])
            ->exists($this->db);
    }

    /**
     * Фиксирует миграцию в штатной истории (идемпотентно — повторная запись игнорируется).
     */
    public function record(string $version): void
    {
        $this->ensureSchema();

        if ($this->isApplied($version)) {
            return;
        }

        $this->db->createCommand()->insert(self::TABLE, [
            'version' => $version,
            'apply_time' => time(),
        ])->execute();
    }

    public function forget(string $version): void
    {
        $this->ensureSchema();

        $this->db->createCommand()->delete(self::TABLE, ['version' => $version])->execute();
    }
}
