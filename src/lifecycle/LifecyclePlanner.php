<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\lifecycle;

use Besnovatyj\Modman\catalog\ModuleManifest;
use Besnovatyj\Modman\catalog\PackageCatalog;
use Besnovatyj\Modman\compiler\ArtifactPaths;
use Besnovatyj\Modman\deps\DependencyResolver;
use Besnovatyj\Modman\deps\exception\DependencyException;
use Besnovatyj\Modman\lifecycle\plan\LifecyclePlan;
use Besnovatyj\Modman\lifecycle\plan\PlannedStep;
use Besnovatyj\Modman\registry\ModuleRegistry;
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
        if (!$manifest->editable) {
            // Системный модуль (например, сам менеджер) ставится установочным скриптом CMS, а из
            // админки неприкасаем — симметрично запрету удаления в planUninstall().
            $blockers[] = "Модуль '{$manifest->id}' системный — установка через менеджер недоступна.";
        }

        try {
            $this->deps->assertCanInstall($manifest);
        } catch (DependencyException $e) {
            $blockers[] = $e->getMessage();
        }

        foreach ($this->detectConflicts($manifest) as $conflict) {
            $blockers[] = $conflict;
        }

        // Недоступность записи гарантирует провал recompile/создания директорий — это блокер, а не
        // предупреждение, иначе dry-run соврёт «выполнимо».
        foreach ($this->checkWritable($manifest) as $blocker) {
            $blockers[] = $blocker;
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

        // Удаление тоже завершается recompile, а при наличии директорий — их физическим удалением.
        // Недоступность записи гарантирует частично удалённый модуль, поэтому это блокер, а не warning
        // (паритет с planInstall/planUpdate).
        foreach ($this->checkArtifactsWritable() as $blocker) {
            $blockers[] = $blocker;
        }
        if ($manifest !== null && $manifest->contributions->directories !== []) {
            foreach ($this->checkStaticWritable() as $blocker) {
                $blockers[] = $blocker;
            }
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

        // Паритет с planInstall: новая версия могла добавить зависимости, конфликты компонентов/bootstrap
        // или потребовать запись туда, куда нельзя. detectConflicts исключает сам обновляемый модуль,
        // иначе его же вклады (он установлен) дали бы ложный самоконфликт.
        try {
            $this->deps->assertCanInstall($manifest);
        } catch (DependencyException $e) {
            $blockers[] = $e->getMessage();
        }
        foreach ($this->detectConflicts($manifest, $manifest->id) as $conflict) {
            $blockers[] = $conflict;
        }
        foreach ($this->checkWritable($manifest) as $blocker) {
            $blockers[] = $blocker;
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
    private function detectConflicts(ModuleManifest $manifest, ?string $excludeId = null): array
    {
        $conflicts = [];

        [$components, $bootstrap] = $this->managedContributions($excludeId);

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
     * @param string|null $excludeId не учитывать этот модуль (например, сам себя при update).
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function managedContributions(?string $excludeId = null): array
    {
        $components = [];
        $bootstrap = [];
        foreach ($this->registry->all() as $id => $state) {
            if ($id === $excludeId || !$state->status->isActive()) {
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
        $blockers = $this->checkArtifactsWritable();

        if ($manifest->contributions->directories !== []) {
            $blockers = array_merge($blockers, $this->checkStaticWritable());
        }

        return $blockers;
    }

    /**
     * Запись артефактов нужна ЛЮБОЙ операции (любая завершается recompile), поэтому проверяется и при
     * установке/обновлении, и при удалении.
     *
     * @return string[]
     */
    private function checkArtifactsWritable(): array
    {
        foreach ($this->paths->all() as $artifact) {
            $dir = dirname($artifact);
            if (!is_dir($dir) || !is_writable($dir)) {
                return ["Директория артефактов недоступна для записи: {$dir}"];
            }
        }

        return [];
    }

    /**
     * Запись в домен статики нужна для создания/удаления директорий модуля.
     *
     * @return string[]
     */
    private function checkStaticWritable(): array
    {
        $staticBase = Yii::getAlias('@static', false);
        if ($staticBase === false || !is_writable($staticBase)) {
            return ["Домен статики недоступен для записи: " . ($staticBase ?: '@static')];
        }

        return [];
    }
}
