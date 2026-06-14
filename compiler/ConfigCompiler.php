<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\compiler;

use modules\modman\catalog\ModuleManifest;
use modules\modman\catalog\PackageCatalog;
use modules\modman\registry\ModuleRegistry;

/**
 * Сердце архитектурного закона: производные конфиги собираются ЦЕЛИКОМ из (реестр × манифесты).
 *
 * `compile()` — чистая функция (только чтение реестра и каталога), `persist()` — единственная запись
 * через {@see AtomicWriter}. Это устраняет инкрементальные правки и `*_backup` старого modman:
 * откат любой операции = «вернуть реестр и перекомпилировать».
 *
 * Источник истины о наборе модулей — реестр (статус installed). Контент вкладов берётся из манифеста
 * того же модуля в каталоге.
 */
final class ConfigCompiler
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PackageCatalog $catalog,
        private readonly MenuCompiler   $menuCompiler,
        private readonly AtomicWriter   $writer,
        private readonly ArtifactPaths  $paths,
    ) {}

    /**
     * Чистая компиляция: текущее состояние реестра → артефакты (без записи на диск).
     */
    public function compile(): CompiledArtifacts
    {
        $modules = [];
        $bootstrap = [];
        $components = [];
        $logChannels = [];
        $options = [];
        $menuContributions = [];
        $warnings = [];
        $compiled = [];

        // Системные модули (editable=false) активны ВСЕГДА: они ставятся ядром/бутстрапом и в реестре
        // их нет (как у самого менеджера). Без этого их конфиг и меню вымывались бы при каждой recompile
        // — модуль «исчезал» бы из приложения после любой install/uninstall.
        foreach ($this->catalog->manifests() as $id => $manifest) {
            if (!$manifest->editable) {
                $this->addManifest($manifest, $modules, $bootstrap, $components, $logChannels, $options, $menuContributions, $warnings);
                $compiled[$id] = true;
            }
        }

        // Модули, согласованно установленные через реестр.
        foreach ($this->registry->all() as $id => $state) {
            if (!$state->status->isActive() || isset($compiled[$id])) {
                continue;
            }

            $manifest = $this->catalog->findById($id);
            if ($manifest === null) {
                $warnings[] = "Модуль '{$id}' установлен в реестре, но пакет не найден в каталоге — пропущен в конфиге.";
                continue;
            }

            $this->addManifest($manifest, $modules, $bootstrap, $components, $logChannels, $options, $menuContributions, $warnings);
            $compiled[$id] = true;
        }

        $menusByLocation = $this->menuCompiler->compile($this->menuCompiler->flatten($menuContributions));

        // Детерминизм: стабильный порядок ключей.
        ksort($modules);
        ksort($components);
        ksort($logChannels);
        ksort($options);
        sort($bootstrap);

        return new CompiledArtifacts(
            modules: $modules,
            bootstrap: $bootstrap,
            components: $components,
            logChannels: $logChannels,
            options: $options,
            menusByLocation: $menusByLocation,
            warnings: $warnings,
        );
    }

    /**
     * Перекомпилировать и атомарно записать все артефакты. Идемпотентно.
     */
    public function recompile(): CompiledArtifacts
    {
        $artifacts = $this->compile();
        $this->persist($artifacts);
        return $artifacts;
    }

    /**
     * Перекомпилировать и записать ТОЛЬКО артефакты меню — точечная операция для диагностики/обслуживания
     * (аналог `modman/menu/rebuild`). Прочие артефакты не трогаются.
     */
    public function recompileMenus(): CompiledArtifacts
    {
        $artifacts = $this->compile();
        foreach ($this->paths->menuLocationFiles as $location => $file) {
            $this->writer->writeArray($file, $artifacts->menusByLocation[$location] ?? []);
        }
        return $artifacts;
    }

    /**
     * Записать артефакты на диск (атомарно, по одному файлу).
     */
    public function persist(CompiledArtifacts $artifacts): void
    {
        $this->writer->writeArray($this->paths->modulesConfig, $artifacts->modules);
        $this->writer->writeArray($this->paths->bootstrapConfig, $artifacts->bootstrap);
        $this->writer->writeArray($this->paths->componentsConfig, $artifacts->components);
        $this->writer->writeArray($this->paths->logChannelsConfig, $artifacts->logChannels);
        $this->writer->writeArray($this->paths->optionsConfig, $artifacts->options);

        foreach ($this->paths->menuLocationFiles as $location => $file) {
            $this->writer->writeArray($file, $artifacts->menusByLocation[$location] ?? []);
        }
    }

    /**
     * Накопить вклады одного манифеста в собираемые артефакты. Общий код для системных (всегда активных)
     * и установленных через реестр модулей.
     *
     * @param array<string, array> $modules
     * @param string[]             $bootstrap
     * @param array<string, array> $components
     * @param array<string, array> $logChannels
     * @param array<string, array> $options
     * @param array<int, array>    $menuContributions
     * @param string[]             $warnings
     */
    private function addManifest(
        ModuleManifest $manifest,
        array &$modules,
        array &$bootstrap,
        array &$components,
        array &$logChannels,
        array &$options,
        array &$menuContributions,
        array &$warnings,
    ): void {
        $id = $manifest->id;
        $modules[$id] = $manifest->compiledModuleConfig();

        foreach ($manifest->contributions->bootstrap as $class) {
            if (!in_array($class, $bootstrap, true)) {
                $bootstrap[] = $class;
            }
        }

        $this->mergeNamed($components, $manifest->contributions->components, $id, 'компонент', $warnings);
        $this->mergeNamed($logChannels, $manifest->contributions->logChannels, $id, 'канал лога', $warnings);

        if ($manifest->contributions->options !== []) {
            $options[$id] = $manifest->contributions->options;
        }

        if ($manifest->contributions->hasAdminMenu()) {
            $menuContributions[] = $manifest->contributions->adminMenu;
        }
    }

    /**
     * Слияние именованных вкладов (компоненты/каналы) с детекцией конфликта имён (первый побеждает).
     *
     * @param array<string, array> $target
     * @param array<string, array> $source
     * @param string[]             $warnings
     */
    private function mergeNamed(array &$target, array $source, string $moduleId, string $kind, array &$warnings): void
    {
        foreach ($source as $name => $config) {
            if (array_key_exists($name, $target)) {
                $warnings[] = "Конфликт имён ({$kind}) '{$name}' при компиляции модуля '{$moduleId}' — оставлен ранее зарегистрированный.";
                continue;
            }
            $target[$name] = $config;
        }
    }
}
