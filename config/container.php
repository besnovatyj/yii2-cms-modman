<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\Helpers\ArrayExportHelper;
use modules\modmanNew\catalog\ManifestFactory;
use modules\modmanNew\catalog\PackageCatalog;
use modules\modmanNew\catalog\source\ComposerInstalledModuleSource;
use modules\modmanNew\catalog\source\FilesystemModuleSource;
use modules\modmanNew\compiler\ArtifactPaths;
use modules\modmanNew\compiler\AtomicWriter;
use modules\modmanNew\compiler\ConfigCompiler;
use modules\modmanNew\compiler\MenuCompiler;
use modules\modmanNew\deps\DependencyResolver;
use modules\modmanNew\events\ModuleLifecycleDispatcher;
use modules\modmanNew\lifecycle\handler\CheckHandler;
use modules\modmanNew\lifecycle\handler\InstallHandler;
use modules\modmanNew\lifecycle\handler\ReconcileHandler;
use modules\modmanNew\lifecycle\handler\UninstallHandler;
use modules\modmanNew\lifecycle\handler\UpdateHandler;
use modules\modmanNew\lifecycle\LifecycleExecutor;
use modules\modmanNew\lifecycle\LifecycleLock;
use modules\modmanNew\lifecycle\LifecyclePlanner;
use modules\modmanNew\lifecycle\step\CreateDirectoriesStep;
use modules\modmanNew\lifecycle\step\RemoveDirectoriesStep;
use modules\modmanNew\lifecycle\step\RevertMigrationsStep;
use modules\modmanNew\lifecycle\step\RunMigrationsStep;
use modules\modmanNew\migration\MigrationOwnershipRepository;
use modules\modmanNew\migration\ModuleMigrationRunner;
use modules\modmanNew\ModuleManager;
use modules\modmanNew\registry\ModuleRegistry;
use yii\di\Container;
use yii\mutex\FileMutex;

/**
 * DI-проводка новой системы управления модулями.
 *
 * Все сервисы — синглтоны: каталог кэширует discovery, реестр держит состояние в памяти на запрос.
 * Скалярные/составные аргументы (пути, наборы источников) задаются явно; остальное собирается из
 * контейнера. Вызывается из {@see \modules\modmanNew\Bootstrap}.
 */
return function (Container $container): void {
    $params = require __DIR__ . '/params.php';

    // --- Инфраструктура записи ---------------------------------------------------------------
    $container->setSingleton(ArrayExportHelper::class, ArrayExportHelper::class);
    $container->setSingleton(AtomicWriter::class, static fn(Container $c): AtomicWriter
        => new AtomicWriter($c->get(ArrayExportHelper::class)));

    // --- Пути артефактов ---------------------------------------------------------------------
    $container->setSingleton(ArtifactPaths::class, static function () use ($params): ArtifactPaths {
        $menuFiles = [];
        foreach ($params['menuLocations'] as $location => $cfg) {
            if ($cfg['enabled'] ?? false) {
                $menuFiles[$location] = Yii::getAlias($cfg['file']);
            }
        }
        return new ArtifactPaths(
            modulesConfig: Yii::getAlias($params['artifacts']['modules']),
            bootstrapConfig: Yii::getAlias($params['artifacts']['bootstrap']),
            componentsConfig: Yii::getAlias($params['artifacts']['components']),
            logChannelsConfig: Yii::getAlias($params['artifacts']['logChannels']),
            optionsConfig: Yii::getAlias($params['artifacts']['options']),
            menuLocationFiles: $menuFiles,
        );
    });

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
        ));

    // --- Компилятор --------------------------------------------------------------------------
    $container->setSingleton(MenuCompiler::class, static fn(): MenuCompiler
        => new MenuCompiler(array_keys($params['menuLocations']), $params['menuDefaults']));
    $container->setSingleton(ConfigCompiler::class, static fn(Container $c): ConfigCompiler
        => new ConfigCompiler(
            $c->get(ModuleRegistry::class),
            $c->get(PackageCatalog::class),
            $c->get(MenuCompiler::class),
            $c->get(AtomicWriter::class),
            $c->get(ArtifactPaths::class),
        ));

    // --- Зависимости -------------------------------------------------------------------------
    $container->setSingleton(DependencyResolver::class, static fn(Container $c): DependencyResolver
        => new DependencyResolver($c->get(ModuleRegistry::class), $c->get(PackageCatalog::class)));

    // --- Миграции ----------------------------------------------------------------------------
    $container->setSingleton(MigrationOwnershipRepository::class, static fn(): MigrationOwnershipRepository
        => new MigrationOwnershipRepository(Yii::$app->db));
    $container->setSingleton(ModuleMigrationRunner::class, static fn(Container $c): ModuleMigrationRunner
        => new ModuleMigrationRunner(Yii::$app->db, $c->get(MigrationOwnershipRepository::class)));

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
            $c->get(MigrationOwnershipRepository::class),
            $c->get(ConfigCompiler::class),
            $c->get(LifecycleLock::class),
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
            $c->get(ConfigCompiler::class),
        ));
};
