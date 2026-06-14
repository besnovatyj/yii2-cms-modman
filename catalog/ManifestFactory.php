<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\catalog;

use modules\modmanNew\catalog\exception\ManifestException;
use modules\modmanNew\catalog\source\DiscoveredPackage;
use modules\modmanNew\contract\DeclaresModule;
use modules\modmanNew\contract\ProvidesAdminMenu;
use modules\modmanNew\contract\ProvidesBootstrap;
use modules\modmanNew\contract\ProvidesComponents;
use modules\modmanNew\contract\ProvidesDependencies;
use modules\modmanNew\contract\ProvidesDirectories;
use modules\modmanNew\contract\ProvidesLogChannels;
use modules\modmanNew\contract\ProvidesMigrations;
use modules\modmanNew\contract\ProvidesOptions;
use modules\modmanNew\registry\Version;
use Yii;

/**
 * Собирает {@see ModuleManifest} из {@see DiscoveredPackage}, опрашивая контракты класса модуля.
 *
 * Ключевая особенность: возможности модуля определяются через `class_implements()` (типизированный
 * контракт), а не через `method_exists()`. Класс модуля при этом НЕ инстанцируется — вызываются
 * только статические методы контрактов.
 */
final class ManifestFactory
{
    /**
     * @throws ManifestException если пакет не является валидным модулем нового контракта
     */
    public function fromPackage(DiscoveredPackage $package): ModuleManifest
    {
        if (!$package->isModule()) {
            throw new ManifestException(
                "Пакет '{$package->composerName}' не является модулем (нет extra.moduleClass/moduleId)."
            );
        }

        /** @var class-string $class */
        $class = $package->moduleClass;

        if (!class_exists($class)) {
            throw new ManifestException(
                "Класс модуля '{$class}' недоступен в автозагрузке (пакет '{$package->composerName}'). "
                . 'Установлен ли пакет через composer?'
            );
        }

        if (!$this->implementsContract($class, DeclaresModule::class)) {
            throw new ManifestException(
                "Класс '{$class}' должен реализовывать контракт " . DeclaresModule::class . '.'
            );
        }

        $id = $class::moduleId();
        if ($id !== $package->moduleId) {
            throw new ManifestException(
                "Несоответствие moduleId: composer.json объявляет '{$package->moduleId}', "
                . "а класс {$class}::moduleId() возвращает '{$id}'."
            );
        }

        $version = new Version($class::moduleVersion());
        $requirements = $this->implementsContract($class, ProvidesDependencies::class)
            ? Requirements::fromArray($class::dependencies())
            : Requirements::empty();

        $contributions = new Contributions(
            components: $this->implementsContract($class, ProvidesComponents::class) ? $class::components() : [],
            bootstrap: $this->implementsContract($class, ProvidesBootstrap::class) ? array_values($class::bootstrapClasses()) : [],
            adminMenu: $this->implementsContract($class, ProvidesAdminMenu::class) ? $class::adminMenu() : [],
            options: $this->implementsContract($class, ProvidesOptions::class) ? $class::options() : [],
            logChannels: $this->implementsContract($class, ProvidesLogChannels::class) ? $class::logChannels() : [],
            directories: $this->implementsContract($class, ProvidesDirectories::class) ? $this->buildDirectories($class::directories()) : [],
            migrationPath: $this->implementsContract($class, ProvidesMigrations::class) ? Yii::getAlias($class::migrationPath()) : null,
            migrationNamespace: $this->implementsContract($class, ProvidesMigrations::class) ? $class::migrationNamespace() : null,
        );

        return new ModuleManifest(
            id: $id,
            package: $package->composerName,
            moduleClass: $class,
            version: $version,
            editable: $class::isEditable(),
            config: $class::moduleConfig(),
            requirements: $requirements,
            contributions: $contributions,
            path: $package->path,
            checksum: $this->checksum($package, $class, $id, $version, $requirements, $contributions),
        );
    }

    private function implementsContract(string $class, string $interface): bool
    {
        return isset(class_implements($class)[$interface]);
    }

    /**
     * @param array<int|string, string|int> $declared
     * @return RequiredDirectory[]
     */
    private function buildDirectories(array $declared): array
    {
        $dirs = [];
        foreach ($declared as $key => $value) {
            if (is_string($key)) {
                // 'путь' => режим
                $dirs[] = new RequiredDirectory($key, (int)$value);
            } else {
                // элемент-строка
                $dirs[] = new RequiredDirectory((string)$value);
            }
        }
        return $dirs;
    }

    /**
     * Контрольная сумма функционального состояния манифеста.
     *
     * Включает только то, что влияет на установку/обновление (для детекции изменений при update).
     * Меню и `config` исключены: они могут содержать Closure и являются производными/косметическими.
     */
    private function checksum(
        DiscoveredPackage $package,
        string            $class,
        string            $id,
        Version           $version,
        Requirements      $requirements,
        Contributions     $contributions,
    ): string {
        $normalized = [
            'id' => $id,
            'class' => $class,
            'package' => $package->composerName,
            'version' => $version->value,
            'requirements' => $requirements->toArray(),
            'components' => array_keys($contributions->components),
            'bootstrap' => $contributions->bootstrap,
            'logChannels' => array_keys($contributions->logChannels),
            'migrationPath' => $contributions->migrationPath,
            'migrationNamespace' => $contributions->migrationNamespace,
            'directories' => array_map(
                static fn(RequiredDirectory $d): array => [$d->path, $d->mode],
                $contributions->directories,
            ),
        ];

        return hash('sha256', (string)json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}
