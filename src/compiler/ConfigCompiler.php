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
use yii\helpers\ArrayHelper;

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
        private readonly ViewSourcesResolver $viewSourcesResolver,
        private readonly MergePlanCompiler   $mergePlanCompiler,
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
        // Тема-НЕзависимый манифест источников представлений: корневой ключ приложения + модули.
        $viewSources = [ViewSourcesManifest::APP_VIEWS_KEY => ''];
        // Пер-аппликационные вклады: appId => частичное дерево конфига (merge нескольких модулей).
        $appConfig = [];
        $warnings = [];
        $compiled = [];

        // Системные модули (editable=false) активны ВСЕГДА: они ставятся ядром/бутстрапом и в реестре
        // их нет (как у самого менеджера). Без этого их конфиг и меню вымывались бы при каждой recompile
        // — модуль «исчезал» бы из приложения после любой install/uninstall.
        foreach ($this->catalog->manifests() as $id => $manifest) {
            if (!$manifest->editable) {
                $this->addManifest($manifest, $modules, $bootstrap, $components, $logChannels, $options, $menuContributions, $viewSources, $appConfig, $warnings);
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

            $this->addManifest($manifest, $modules, $bootstrap, $components, $logChannels, $options, $menuContributions, $viewSources, $appConfig, $warnings);
            $compiled[$id] = true;
        }

        $menusByLocation = $this->menuCompiler->compile($this->menuCompiler->flatten($menuContributions));

        // Детерминизм: стабильный порядок ключей.
        ksort($modules);
        ksort($components);
        ksort($logChannels);
        ksort($options);
        ksort($viewSources);
        ksort($appConfig);
        sort($bootstrap);

        return new CompiledArtifacts(
            modules: $modules,
            bootstrap: $bootstrap,
            components: $components,
            logChannels: $logChannels,
            options: $options,
            menusByLocation: $menusByLocation,
            viewSources: $viewSources,
            appConfig: $appConfig,
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
     * @deprecated Меню собираются в рантайме из группы `admin-menu` (yiisoft/config, {@see \Besnovatyj\Modman\menu\MenuProvider}),
     * а не сериализуются в `menu-*.php` (замыкания `active` не переживают var_export). Метод оставлен для
     * совместимости вызывающих (web/console «пересобрать меню») — теперь без записи артефактов меню.
     */
    public function recompileMenus(): CompiledArtifacts
    {
        return $this->compile();
    }

    /**
     * Записать артефакты на диск (атомарно, по одному файлу).
     *
     * Меню НЕ пишутся: они собираются в рантайме из группы `admin-menu` — единственные замыкания, ради
     * которых был нужен экспорт замыканий; их сериализация упразднена вместе с exportClosure.
     */
    public function persist(CompiledArtifacts $artifacts): void
    {
        // modules / components / bootstrap / appConfig больше НЕ генерим: они собираются движком
        // yiisoft/config по merge-plan (config-plugin, registry-gated через MergePlanCompiler).
        // Остаётся генерация:
        //  - logChannels — registry-gated лог-каналы устанавливаемых модулей (по требованию владельца,
        //    активируются modman'ом, а не глобальным composer-bootstrap; см. common/config/log.php);
        //  - options — реестр опций для модуля конфигурации;
        //  - viewSources — тема-независимый манифест источников представлений (темизация).
        $this->writer->writeArray($this->paths->logChannelsConfig, $artifacts->logChannels);
        $this->writer->writeArray($this->paths->optionsConfig, $artifacts->options);
        $this->writer->writeArray($this->paths->viewSourcesConfig, $artifacts->viewSources);
    }

    /**
     * Накопить вклады одного манифеста в собираемые артефакты. Общий код для системных (всегда активных)
     * и установленных через реестр модулей.
     *
     * @param array<string, array> $modules
     * @param string[]             $bootstrap
     * @param array<string, array> $components
     * @param array<string, array> $logChannels
     * @param array<string, array>  $options
     * @param array<int, array>     $menuContributions
     * @param array<string, string> $viewSources
     * @param array<string, array>  $appConfig
     * @param string[]              $warnings
     */
    private function addManifest(
        ModuleManifest $manifest,
        array &$modules,
        array &$bootstrap,
        array &$components,
        array &$logChannels,
        array &$options,
        array &$menuContributions,
        array &$viewSources,
        array &$appConfig,
        array &$warnings,
    ): void {
        $id = $manifest->id;

        // --- НЕ-Yii2-конфиг: остаётся у modman всегда, независимо от config-plugin ---------------

        // Источник представлений модуля (алиасный путь views/) для тема-независимого манифеста.
        $sourceAlias = $this->viewSourcesResolver->sourceAlias($manifest->moduleClass);
        if ($sourceAlias !== null) {
            $viewSources[$id] = $sourceAlias;
        }

        // Опции (агрегат для модуля конфигурации) — не Yii2-конфиг приложения.
        if ($manifest->contributions->options !== []) {
            $options[$id] = $manifest->contributions->options;
        }

        // Меню (система меню modman → артефакты menu-*.php) — не Yii2-конфиг приложения.
        if ($manifest->contributions->hasAdminMenu()) {
            $menuContributions[] = $manifest->contributions->adminMenu;
        }

        // --- Yii2-конфиг приложения ------------------------------------------------------------
        // Если модуль объявил config-plugin, его конфиг (modules[]/components/bootstrap/as*) собирается
        // движком yiisoft/config по merge-plan (см. MergePlanCompiler). Тогда старые артефакты его НЕ
        // включают — иначе двойная загрузка. См. /TODO_YII3_CONFIG.MD.
        if ($manifest->contributions->configPlugin !== []) {
            return;
        }

        $modules[$id] = $manifest->compiledModuleConfig();

        foreach ($manifest->contributions->bootstrap as $class) {
            if (!in_array($class, $bootstrap, true)) {
                $bootstrap[] = $class;
            }
        }

        $this->mergeNamed($components, $manifest->contributions->components, $id, 'компонент', $warnings);
        $this->mergeNamed($logChannels, $manifest->contributions->logChannels, $id, 'канал лога', $warnings);

        // Пер-аппликационный вклад: частичные деревья конфига мёржатся по appId (deep merge —
        // allowActions нескольких модулей конкатенируются, компоненты дополняются). Вклад уже
        // отфильтрован политикой в ManifestFactory, поэтому as access.class сюда попасть не может.
        foreach ($manifest->contributions->appConfig as $appId => $contribution) {
            $appConfig[$appId] = isset($appConfig[$appId])
                ? ArrayHelper::merge($appConfig[$appId], $contribution)
                : $contribution;
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
