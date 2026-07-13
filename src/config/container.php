<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\Helpers\ArrayExportHelper;
use Besnovatyj\Modman\catalog\check\L1BootstrapCheck;
use Besnovatyj\Modman\catalog\ManifestFactory;
use Besnovatyj\Modman\catalog\PackageCatalog;
use Besnovatyj\Modman\catalog\source\ComposerInstalledModuleSource;
use Besnovatyj\Modman\catalog\source\FilesystemModuleSource;
use Besnovatyj\Modman\compiler\ArtifactPaths;
use Besnovatyj\Modman\compiler\AtomicWriter;
use Besnovatyj\Modman\compiler\ConfigCompiler;
use Besnovatyj\Modman\compiler\MergePlanCompiler;
use Besnovatyj\Modman\compiler\MenuCompiler;
use Besnovatyj\Modman\compiler\ViewSourcesResolver;
use Besnovatyj\Modman\deps\DependencyResolver;
use Besnovatyj\Modman\events\ModuleLifecycleDispatcher;
use Besnovatyj\Modman\lifecycle\handler\CheckHandler;
use Besnovatyj\Modman\lifecycle\handler\InstallHandler;
use Besnovatyj\Modman\lifecycle\handler\ReconcileHandler;
use Besnovatyj\Modman\lifecycle\handler\SyncHandler;
use Besnovatyj\Modman\lifecycle\handler\UninstallHandler;
use Besnovatyj\Modman\lifecycle\handler\UpdateHandler;
use Besnovatyj\Modman\lifecycle\LifecycleExecutor;
use Besnovatyj\Modman\lifecycle\LifecycleLock;
use Besnovatyj\Modman\lifecycle\LifecyclePlanner;
use Besnovatyj\Modman\lifecycle\step\CreateDirectoriesStep;
use Besnovatyj\Modman\lifecycle\step\RemoveDirectoriesStep;
use Besnovatyj\Modman\lifecycle\step\RevertMigrationsStep;
use Besnovatyj\Modman\lifecycle\step\RunMigrationsStep;
use Besnovatyj\Modman\migration\MigrationOwnershipRepository;
use Besnovatyj\Modman\migration\ModuleMigrationRunner;
use Besnovatyj\Modman\migration\StandardMigrationHistory;
use Besnovatyj\Modman\ModuleManager;
use Besnovatyj\Modman\registry\ModuleRegistry;
use Besnovatyj\Modman\upstream\GitHubTagFetcher;
use yii\di\Container;
use yii\mutex\FileMutex;

/**
 * DI-проводка системы управления модулями.
 *
 * Все сервисы — синглтоны: каталог кэширует discovery, реестр держит состояние в памяти на запрос.
 * Скалярные/составные аргументы (пути, наборы источников) задаются явно; остальное собирается из
 * контейнера. Вызывается из {@see \Besnovatyj\Modman\Bootstrap}.
 */
return function (Container $container): void {
    $params = require __DIR__ . '/params.php';

    // --- Инфраструктура записи ---------------------------------------------------------------
    $container->setSingleton(ArrayExportHelper::class, ArrayExportHelper::class);
    $container->setSingleton(AtomicWriter::class, static fn(Container $c): AtomicWriter
        => new AtomicWriter($c->get(ArrayExportHelper::class)));

    // --- Пути артефактов ---------------------------------------------------------------------
    $container->setSingleton(ArtifactPaths::class, static fn(): ArtifactPaths => new ArtifactPaths(
        logChannelsConfig: Yii::getAlias($params['artifacts']['logChannels']),
        optionsConfig: Yii::getAlias($params['artifacts']['options']),
        viewSourcesConfig: Yii::getAlias($params['artifacts']['viewSources']),
    ));

    // --- Реестр состояния --------------------------------------------------------------------
    $container->setSingleton(ModuleRegistry::class, static fn(Container $c): ModuleRegistry
        => new ModuleRegistry(Yii::getAlias($params['lockFile']), $c->get(AtomicWriter::class)));

    // --- Каталог и discovery -----------------------------------------------------------------
    $container->setSingleton(ManifestFactory::class, ManifestFactory::class);
    $container->setSingleton(PackageCatalog::class, static fn(Container $c): PackageCatalog
        => new PackageCatalog(
            sources: [
                new FilesystemModuleSource($params['scanDirs']),
                new ComposerInstalledModuleSource(),
            ],
            factory: $c->get(ManifestFactory::class),
            // Не-блокирующие проверки конвенций (WarningModule); расширение — добавить класс в список.
            warningChecks: [
                new L1BootstrapCheck($params['l1BootstrapAllowlist'] ?? []),
            ],
        ));

    // --- Компилятор --------------------------------------------------------------------------
    $container->setSingleton(MenuCompiler::class, static fn(): MenuCompiler
        => new MenuCompiler(array_keys($params['menuLocations']), $params['menuDefaults']));

    // Рантайм-сборка меню из группы admin-menu (yiisoft/config) — вместо сериализованных menu-*.php.
    $container->setSingleton(\Besnovatyj\Modman\menu\MenuProvider::class, static fn(Container $c): \Besnovatyj\Modman\menu\MenuProvider
        => new \Besnovatyj\Modman\menu\MenuProvider($c->get(MenuCompiler::class)));
    $container->setSingleton(ViewSourcesResolver::class, ViewSourcesResolver::class);

    // Merge-plan для yiisoft/config: root config-plugin читаем из корневого composer.json приложения.
    $container->setSingleton(MergePlanCompiler::class, static function (Container $c) use ($params): MergePlanCompiler {
        $rootConfigPlugin = [];
        $composerPath = Yii::getAlias('@root/composer.json', false);
        if (is_string($composerPath) && is_file($composerPath)) {
            $decoded = json_decode((string)file_get_contents($composerPath), true);
            if (is_array($decoded) && is_array($decoded['extra']['config-plugin'] ?? null)) {
                $rootConfigPlugin = $decoded['extra']['config-plugin'];
            }
        }
        return new MergePlanCompiler(
            $c->get(ModuleRegistry::class),
            $c->get(PackageCatalog::class),
            $c->get(AtomicWriter::class),
            Yii::getAlias($params['artifacts']['mergePlan']),
            $rootConfigPlugin,
        );
    });

    $container->setSingleton(ConfigCompiler::class, static fn(Container $c): ConfigCompiler
        => new ConfigCompiler(
            $c->get(ModuleRegistry::class),
            $c->get(PackageCatalog::class),
            $c->get(AtomicWriter::class),
            $c->get(ArtifactPaths::class),
            $c->get(ViewSourcesResolver::class),
            $c->get(MergePlanCompiler::class),
        ));

    // --- Зависимости -------------------------------------------------------------------------
    $container->setSingleton(DependencyResolver::class, static fn(Container $c): DependencyResolver
        => new DependencyResolver($c->get(ModuleRegistry::class), $c->get(PackageCatalog::class)));

    // --- Миграции ----------------------------------------------------------------------------
    $container->setSingleton(MigrationOwnershipRepository::class, static fn(): MigrationOwnershipRepository
        => new MigrationOwnershipRepository(Yii::$app->db));
    $container->setSingleton(StandardMigrationHistory::class, static fn(): StandardMigrationHistory
        => new StandardMigrationHistory(Yii::$app->db));
    $container->setSingleton(ModuleMigrationRunner::class, static fn(Container $c): ModuleMigrationRunner
        => new ModuleMigrationRunner(
            Yii::$app->db,
            $c->get(MigrationOwnershipRepository::class),
            $c->get(StandardMigrationHistory::class),
        ));

    // --- Шаги lifecycle ----------------------------------------------------------------------
    $container->setSingleton(RunMigrationsStep::class, static fn(Container $c): RunMigrationsStep
        => new RunMigrationsStep($c->get(ModuleMigrationRunner::class)));
    $container->setSingleton(CreateDirectoriesStep::class, CreateDirectoriesStep::class);
    $container->setSingleton(RevertMigrationsStep::class, static fn(Container $c): RevertMigrationsStep
        => new RevertMigrationsStep($c->get(ModuleMigrationRunner::class), $c->get(MigrationOwnershipRepository::class)));
    $container->setSingleton(RemoveDirectoriesStep::class, RemoveDirectoriesStep::class);

    // --- Блокировка и исполнитель ------------------------------------------------------------
    $container->setSingleton(LifecycleLock::class, static fn(): LifecycleLock
        => new LifecycleLock(new FileMutex(['mutexPath' => Yii::getAlias($params['mutexPath'])])));
    $container->setSingleton(LifecycleExecutor::class, static fn(Container $c): LifecycleExecutor
        => new LifecycleExecutor($c->get(LifecycleLock::class), $c->get(ConfigCompiler::class)));
    $container->setSingleton(LifecyclePlanner::class, static fn(Container $c): LifecyclePlanner
        => new LifecyclePlanner(
            $c->get(ModuleRegistry::class),
            $c->get(PackageCatalog::class),
            $c->get(DependencyResolver::class),
            $c->get(ArtifactPaths::class),
        ));

    // --- События -----------------------------------------------------------------------------
    $container->setSingleton(ModuleLifecycleDispatcher::class, ModuleLifecycleDispatcher::class);

    // --- Хендлеры ----------------------------------------------------------------------------
    $container->setSingleton(CheckHandler::class, static fn(Container $c): CheckHandler
        => new CheckHandler($c->get(PackageCatalog::class), $c->get(ModuleRegistry::class), $c->get(LifecyclePlanner::class)));
    $container->setSingleton(InstallHandler::class, static fn(Container $c): InstallHandler
        => new InstallHandler(
            $c->get(PackageCatalog::class),
            $c->get(ModuleRegistry::class),
            $c->get(LifecyclePlanner::class),
            $c->get(LifecycleExecutor::class),
            $c->get(ModuleLifecycleDispatcher::class),
            $c->get(RunMigrationsStep::class),
            $c->get(CreateDirectoriesStep::class),
        ));
    $container->setSingleton(UninstallHandler::class, static fn(Container $c): UninstallHandler
        => new UninstallHandler(
            $c->get(PackageCatalog::class),
            $c->get(ModuleRegistry::class),
            $c->get(LifecyclePlanner::class),
            $c->get(LifecycleExecutor::class),
            $c->get(ModuleLifecycleDispatcher::class),
            $c->get(RevertMigrationsStep::class),
            $c->get(RemoveDirectoriesStep::class),
        ));
    $container->setSingleton(UpdateHandler::class, static fn(Container $c): UpdateHandler
        => new UpdateHandler(
            $c->get(PackageCatalog::class),
            $c->get(ModuleRegistry::class),
            $c->get(LifecyclePlanner::class),
            $c->get(LifecycleExecutor::class),
            $c->get(ModuleLifecycleDispatcher::class),
            $c->get(RunMigrationsStep::class),
            $c->get(CreateDirectoriesStep::class),
        ));
    $container->setSingleton(ReconcileHandler::class, static fn(Container $c): ReconcileHandler
        => new ReconcileHandler(
            $c->get(ModuleRegistry::class),
            $c->get(PackageCatalog::class),
            $c->get(ModuleMigrationRunner::class),
            $c->get(ConfigCompiler::class),
            $c->get(LifecycleLock::class),
        ));
    $container->setSingleton(SyncHandler::class, static fn(Container $c): SyncHandler
        => new SyncHandler(
            $c->get(PackageCatalog::class),
            $c->get(ModuleRegistry::class),
            $c->get(MigrationOwnershipRepository::class),
            $c->get(ConfigCompiler::class),
            $c->get(LifecycleLock::class),
        ));

    // --- Upstream-версии (GitHub) ------------------------------------------------------------
    // Кэш ответов — в компоненте `cache` приложения (apcu), если он есть; иначе фетчер работает без кэша.
    $container->setSingleton(GitHubTagFetcher::class, static fn(): GitHubTagFetcher => new GitHubTagFetcher(
        cache: Yii::$app->has('cache') ? Yii::$app->get('cache') : null,
        ttl: (int)($params['upstream']['ttl'] ?? 3600),
        errorTtl: (int)($params['upstream']['errorTtl'] ?? 300),
        timeout: (int)($params['upstream']['timeout'] ?? 5),
    ));

    // --- Фасад -------------------------------------------------------------------------------
    $container->setSingleton(ModuleManager::class, static fn(Container $c): ModuleManager
        => new ModuleManager(
            $c->get(PackageCatalog::class),
            $c->get(ModuleRegistry::class),
            $c->get(CheckHandler::class),
            $c->get(InstallHandler::class),
            $c->get(UninstallHandler::class),
            $c->get(UpdateHandler::class),
            $c->get(ReconcileHandler::class),
            $c->get(SyncHandler::class),
            $c->get(ConfigCompiler::class),
            $c->get(GitHubTagFetcher::class),
        ));
};
