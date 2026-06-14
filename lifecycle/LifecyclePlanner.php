<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle;

use modules\modmanNew\catalog\ModuleManifest;
use modules\modmanNew\catalog\PackageCatalog;
use modules\modmanNew\compiler\ArtifactPaths;
use modules\modmanNew\deps\DependencyResolver;
use modules\modmanNew\deps\exception\DependencyException;
use modules\modmanNew\lifecycle\plan\LifecyclePlan;
use modules\modmanNew\lifecycle\plan\PlannedStep;
use modules\modmanNew\registry\ModuleRegistry;
use Yii;

/**
 * Строит {@see LifecyclePlan} — чистый предпросмотр операции (никаких изменений состояния).
 *
 * Используется хендлером проверки (dry-run) и как валидация перед выполнением. Здесь же —
 * детекция конфликтов имён компонентов/bootstrap и проверка прав на запись артефактов.
 */
final class LifecyclePlanner
{
    public function __construct(
        private readonly ModuleRegistry     $registry,
        private readonly PackageCatalog     $catalog,
        private readonly DependencyResolver $deps,
        private readonly ArtifactPaths      $paths,
    ) {}

    public function planInstall(ModuleManifest $manifest): LifecyclePlan
    {
        $blockers = [];
        $warnings = [];

        if ($this->registry->isInstalled($manifest->id)) {
            $blockers[] = "Модуль '{$manifest->id}' уже установлен.";
        }

        try {
            $this->deps->assertCanInstall($manifest);
        } catch (DependencyException $e) {
            $blockers[] = $e->getMessage();
        }

        foreach ($this->detectConflicts($manifest) as $conflict) {
            $blockers[] = $conflict;
        }

        foreach ($this->checkWritable($manifest) as $warning) {
            $warnings[] = $warning;
        }

        return new LifecyclePlan(
            type: OperationType::Install,
            moduleId: $manifest->id,
            version: $manifest->version->value,
            steps: $this->installSteps($manifest),
            blockers: $blockers,
            warnings: $warnings,
        );
    }

    public function planUninstall(string $moduleId): LifecyclePlan
    {
        $blockers = [];
        $warnings = [];
        $state = $this->registry->get($moduleId);
        $manifest = $this->catalog->findById($moduleId);

        if ($state === null || !$state->status->isActive()) {
            $blockers[] = "Модуль '{$moduleId}' не установлен.";
        }
        if ($manifest !== null && !$manifest->editable) {
            $blockers[] = "Модуль '{$moduleId}' системный — удаление запрещено.";
        }

        try {
            $this->deps->assertCanUninstall($moduleId);
        } catch (DependencyException $e) {
            $blockers[] = $e->getMessage();
        }

        if ($manifest === null) {
            $warnings[] = 'Пакет модуля не найден в каталоге — миграции/директории откатить не удастся.';
        }

        $steps = [];
        if ($manifest !== null && $manifest->contributions->hasMigrations()) {
            $steps[] = new PlannedStep('Откатить миграции БД модуля', $manifest->contributions->migrationPath);
        }
        if ($manifest !== null && $manifest->contributions->directories !== []) {
            $steps[] = new PlannedStep('Удалить директории модуля');
        }
        $steps[] = new PlannedStep('Удалить состояние из реестра', $moduleId);
        $steps[] = new PlannedStep('Перекомпилировать конфигурацию');

        return new LifecyclePlan(OperationType::Uninstall, $moduleId, $state?->version->value ?? '', $steps, $blockers, $warnings);
    }

    public function planUpdate(ModuleManifest $manifest): LifecyclePlan
    {
        $blockers = [];
        $warnings = [];
        $state = $this->registry->get($manifest->id);

        if ($state === null || !$state->status->isActive()) {
            $blockers[] = "Модуль '{$manifest->id}' не установлен — обновлять нечего.";
        } elseif (!$manifest->version->isGreaterThan($state->version) && $manifest->checksum === $state->manifestChecksum) {
            $blockers[] = "Нет изменений: установлена версия {$state->version->value}, манифест не менялся.";
        }

        $steps = [
            new PlannedStep('Применить новые (pending) миграции'),
            new PlannedStep('Создать недостающие директории'),
            new PlannedStep('Обновить состояние в реестре', $manifest->version->value),
            new PlannedStep('Перекомпилировать конфигурацию'),
        ];

        return new LifecyclePlan(OperationType::Update, $manifest->id, $manifest->version->value, $steps, $blockers, $warnings);
    }

    /**
     * @return PlannedStep[]
     */
    private function installSteps(ModuleManifest $manifest): array
    {
        $steps = [];
        if ($manifest->contributions->hasMigrations()) {
            $steps[] = new PlannedStep('Применить миграции БД модуля', $manifest->contributions->migrationPath);
        }
        if ($manifest->contributions->directories !== []) {
            $paths = array_map(static fn($d): string => $d->path, $manifest->contributions->directories);
            $steps[] = new PlannedStep('Создать директории модуля', implode(', ', $paths));
        }
        $steps[] = new PlannedStep('Записать состояние в реестр (commit)', $manifest->id);
        $steps[] = new PlannedStep('Перекомпилировать конфигурацию (modules/bootstrap/components/log/menu)');
        return $steps;
    }

    /**
     * Конфликты имён компонентов/bootstrap: с уже управляемыми модулями и с реальным приложением.
     *
     * @return string[]
     */
    private function detectConflicts(ModuleManifest $manifest): array
    {
        $conflicts = [];

        [$components, $bootstrap] = $this->managedContributions();

        foreach (array_keys($manifest->contributions->components) as $name) {
            if (isset($components[$name])) {
                $conflicts[] = "Компонент '{$name}' уже регистрируется модулем '{$components[$name]}'.";
            } elseif (Yii::$app->has($name)) {
                $conflicts[] = "Компонент '{$name}' уже зарегистрирован в приложении.";
            }
        }

        foreach ($manifest->contributions->bootstrap as $class) {
            if (isset($bootstrap[$class])) {
                $conflicts[] = "Bootstrap-класс '{$class}' уже регистрируется модулем '{$bootstrap[$class]}'.";
            }
        }

        return $conflicts;
    }

    /**
     * Имена компонентов/bootstrap, уже занятые управляемыми (installed) модулями.
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function managedContributions(): array
    {
        $components = [];
        $bootstrap = [];
        foreach ($this->registry->all() as $id => $state) {
            if (!$state->status->isActive()) {
                continue;
            }
            $manifest = $this->catalog->findById($id);
            if ($manifest === null) {
                continue;
            }
            foreach (array_keys($manifest->contributions->components) as $name) {
                $components[$name] = $id;
            }
            foreach ($manifest->contributions->bootstrap as $class) {
                $bootstrap[$class] = $id;
            }
        }
        return [$components, $bootstrap];
    }

    /**
     * @return string[]
     */
    private function checkWritable(ModuleManifest $manifest): array
    {
        $warnings = [];

        foreach ($this->paths->all() as $artifact) {
            $dir = dirname($artifact);
            if (!is_dir($dir) || !is_writable($dir)) {
                $warnings[] = "Директория артефактов недоступна для записи: {$dir}";
                break;
            }
        }

        if ($manifest->contributions->directories !== []) {
            $staticBase = Yii::getAlias('@static', false);
            if ($staticBase === false || !is_writable($staticBase)) {
                $warnings[] = "Домен статики недоступен для записи: " . ($staticBase ?: '@static');
            }
        }

        return $warnings;
    }
}
