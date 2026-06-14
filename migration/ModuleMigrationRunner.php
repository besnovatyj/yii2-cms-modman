<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\migration;

use RuntimeException;
use Throwable;
use Yii;
use yii\db\Connection;
use yii\db\Migration;

/**
 * Программный запуск миграций модуля из веб/консоли с учётом владельца.
 *
 * В отличие от старого modman, путь и namespace миграций берутся из манифеста (контракт
 * {@see \modules\modmanNew\contract\ProvidesMigrations}), а не угадываются парсингом PHP. Применённые
 * версии фиксируются за модулем в {@see MigrationOwnershipRepository}, поэтому:
 *  - uninstall откатывает строго свои миграции;
 *  - update применяет только pending (новые) миграции, без down→up.
 */
final class ModuleMigrationRunner
{
    public function __construct(
        private readonly Connection                    $db,
        private readonly MigrationOwnershipRepository  $owners,
    ) {}

    /**
     * Применяет ещё не применённые миграции модуля.
     *
     * @return string[] версии применённых миграций
     */
    public function up(string $moduleId, string $path, ?string $namespace): array
    {
        $this->owners->ensureSchema();
        $migrations = $this->scan($path, $namespace);

        $applied = [];
        foreach ($migrations as $migration) {
            if ($this->owners->isApplied($moduleId, $migration['version'])) {
                continue;
            }

            $this->run($migration, 'up');
            $this->owners->record($moduleId, $migration['version'], $namespace, $migration['file']);
            $applied[] = $migration['version'];
            Yii::info("[{$moduleId}] применена миграция {$migration['version']}", 'modmanNew/migration');
        }

        return $applied;
    }

    /**
     * Откатывает все применённые модулем миграции (в обратном порядке).
     *
     * @return string[] версии откаченных миграций
     */
    public function down(string $moduleId, string $path, ?string $namespace): array
    {
        $applied = $this->owners->appliedVersions($moduleId);
        return $this->revert($moduleId, array_reverse($applied), $path, $namespace);
    }

    /**
     * Откатывает указанные версии (используется для компенсации шага установки).
     *
     * @param string[] $versions
     * @return string[] фактически откаченные версии
     */
    public function revert(string $moduleId, array $versions, string $path, ?string $namespace): array
    {
        $byVersion = [];
        foreach ($this->scan($path, $namespace) as $migration) {
            $byVersion[$migration['version']] = $migration;
        }

        $reverted = [];
        foreach ($versions as $version) {
            $migration = $byVersion[$version] ?? null;
            if ($migration === null) {
                Yii::warning("[{$moduleId}] файл миграции для отката не найден: {$version}", 'modmanNew/migration');
                $this->owners->forget($moduleId, $version);
                continue;
            }

            $this->run($migration, 'down');
            $this->owners->forget($moduleId, $version);
            $reverted[] = $version;
            Yii::info("[{$moduleId}] откачена миграция {$version}", 'modmanNew/migration');
        }

        return $reverted;
    }

    /**
     * Список миграций модуля (отсортирован хронологически по имени файла).
     *
     * @return array<int, array{file:string, version:string}>
     */
    private function scan(string $path, ?string $namespace): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = glob($path . '/m[0-9][0-9][0-9][0-9][0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $migrations[] = [
                'file' => $file,
                'version' => $namespace !== null ? $namespace . '\\' . $className : $className,
            ];
        }

        return $migrations;
    }

    /**
     * @param array{file:string, version:string} $migration
     * @param 'up'|'down' $direction
     */
    private function run(array $migration, string $direction): void
    {
        $instance = $this->instantiate($migration['file']);

        ob_start();
        try {
            $result = $instance->{$direction}();
        } catch (Throwable $e) {
            ob_end_clean();
            throw new RuntimeException(
                "Ошибка миграции ({$direction}) {$migration['version']}: {$e->getMessage()}",
                0,
                $e,
            );
        }
        ob_end_clean();

        if ($result === false) {
            throw new RuntimeException("Миграция {$migration['version']} ({$direction}) вернула false.");
        }
    }

    private function instantiate(string $file): Migration
    {
        require_once $file;

        $className = pathinfo($file, PATHINFO_FILENAME);
        // Определяем FQCN: пробуем namespaced-вариант по содержимому уже подключённого файла.
        $candidates = $this->declaredCandidates($className);
        foreach ($candidates as $candidate) {
            if (class_exists($candidate, false)) {
                /** @var Migration $instance */
                $instance = new $candidate(['db' => $this->db, 'compact' => true]);
                return $instance;
            }
        }

        throw new RuntimeException("Класс миграции для файла '{$file}' не найден после подключения.");
    }

    /**
     * Возможные FQCN миграции: среди объявленных классов выбираем оканчивающиеся на короткое имя.
     *
     * @return string[]
     */
    private function declaredCandidates(string $className): array
    {
        $candidates = [$className]; // без namespace
        foreach (get_declared_classes() as $declared) {
            if ($declared === $className || str_ends_with($declared, '\\' . $className)) {
                $candidates[] = $declared;
            }
        }
        return array_unique($candidates);
    }
}
