<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\compiler;

use Besnovatyj\Contracts\theme\ViewSourcesManifest;
use Besnovatyj\Modman\catalog\ModuleManifest;
use Besnovatyj\Modman\catalog\PackageCatalog;
use Besnovatyj\Modman\registry\ModuleRegistry;

/**
 * Компилятор оставшихся производных артефактов из (реестр × манифесты).
 *
 * После переезда на yiisoft/config Yii2-конфиг приложения (modules/components/bootstrap/appConfig/меню)
 * собирается движком по merge-plan (config-plugin, registry-gated {@see MergePlanCompiler}). Здесь
 * остаётся генерация того, что движком НЕ покрывается:
 *  - `logChannels` — registry-gated лог-каналы устанавливаемых модулей (включаются modman'ом при
 *    активации, а не глобальным composer-bootstrap; потребляется common/config/log.php);
 *  - `options` — реестр опций для модуля конфигурации;
 *  - `viewSources` — тема-независимый манифест источников представлений (темизация).
 *
 * Источник истины о наборе активных модулей — реестр; контент вкладов — из манифеста в каталоге.
 */
final class ConfigCompiler
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PackageCatalog $catalog,
        private readonly AtomicWriter   $writer,
        private readonly ArtifactPaths  $paths,
        private readonly ViewSourcesResolver $viewSourcesResolver,
        private readonly MergePlanCompiler   $mergePlanCompiler,
    ) {}

    /**
     * Чистая компиляция: текущее состояние реестра → артефакты (без записи на диск).
     */
    public function compile(): CompiledArtifacts
    {
        $logChannels = [];
        $options = [];
        // Тема-НЕзависимый манифест источников представлений: корневой ключ приложения + модули.
        $viewSources = [ViewSourcesManifest::APP_VIEWS_KEY => ''];
        $warnings = [];
        $compiled = [];

        // Системные модули (editable=false) активны ВСЕГДА (в реестре их нет, как у самого менеджера).
        foreach ($this->catalog->manifests() as $id => $manifest) {
            if (!$manifest->editable) {
                $this->addManifest($manifest, $logChannels, $options, $viewSources, $warnings);
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

            $this->addManifest($manifest, $logChannels, $options, $viewSources, $warnings);
            $compiled[$id] = true;
        }

        // Детерминизм: стабильный порядок ключей.
        ksort($logChannels);
        ksort($options);
        ksort($viewSources);

        return new CompiledArtifacts(
            logChannels: $logChannels,
            options: $options,
            viewSources: $viewSources,
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
        // Параллельно со старыми артефактами пишем merge-plan для движка yiisoft/config (Yii3).
        // На cutover старые артефакты уйдут, останется план. См. /TODO_YII3_CONFIG.MD.
        $this->mergePlanCompiler->recompile();
        return $artifacts;
    }

    /**
     * Записать артефакты на диск (атомарно, по одному файлу). Единственная точка записи.
     */
    public function persist(CompiledArtifacts $artifacts): void
    {
        $this->writer->writeArray($this->paths->logChannelsConfig, $artifacts->logChannels);
        $this->writer->writeArray($this->paths->optionsConfig, $artifacts->options);
        $this->writer->writeArray($this->paths->viewSourcesConfig, $artifacts->viewSources);
    }

    /**
     * Накопить вклады одного манифеста в оставшиеся артефакты (для системных и registry-active модулей).
     *
     * Всё это — НЕ Yii2-конфиг приложения (тот идёт через config-plugin/merge-plan), поэтому собирается
     * для ЛЮБОГО активного модуля, включая объявившие config-plugin. В частности `logChannels` больше НЕ
     * пропускаются у config-plugin-модулей — иначе будущие shop-модули не смогли бы поставлять свои
     * registry-gated цели логирования.
     *
     * @param array<string, array>  $logChannels channelId => спека
     * @param array<string, array>  $options     id модуля => опции
     * @param array<string, string> $viewSources moduleId => алиасный путь views/
     * @param string[]              $warnings
     */
    private function addManifest(
        ModuleManifest $manifest,
        array &$logChannels,
        array &$options,
        array &$viewSources,
        array &$warnings,
    ): void {
        $id = $manifest->id;

        // Источник представлений модуля (алиасный путь views/) для тема-независимого манифеста.
        $sourceAlias = $this->viewSourcesResolver->sourceAlias($manifest->moduleClass);
        if ($sourceAlias !== null) {
            $viewSources[$id] = $sourceAlias;
        }

        // Опции (агрегат для модуля конфигурации).
        if ($manifest->contributions->options !== []) {
            $options[$id] = $manifest->contributions->options;
        }

        // Registry-gated лог-каналы: включаются modman'ом при активации модуля.
        $this->mergeNamed($logChannels, $manifest->contributions->logChannels, $id, 'канал лога', $warnings);
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
