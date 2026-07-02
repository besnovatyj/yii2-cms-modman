<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\catalog;

use JsonException;
use modules\modman\catalog\exception\ManifestException;
use modules\modman\catalog\source\DiscoveredPackage;
use modules\modman\contract\DeclaresModule;
use modules\modman\contract\ProvidesAdminMenu;
use modules\modman\contract\ProvidesBootstrap;
use modules\modman\contract\ProvidesComponents;
use modules\modman\contract\ProvidesDependencies;
use modules\modman\contract\ProvidesDirectories;
use modules\modman\contract\ProvidesLogChannels;
use modules\modman\contract\ProvidesMigrations;
use modules\modman\contract\ProvidesOptions;
use modules\modman\registry\Version;
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
     * Собирает манифест валидного CMS-модуля нового контракта.
     *
     * Вызывается каталогом только для пакетов, уже помеченных `extra.bescms.kind=module`. Поэтому
     * любое брошенное здесь исключение — это реальная ошибка конфигурации НАШЕГО модуля (а не «чужой
     * пакет»): не переведён на новый контракт, нет moduleClass, рассинхрон id. Каталог покажет такую
     * проблему строкой рядом с модулем (а не flash'ем на всю страницу) — см. {@see InvalidModule}.
     *
     * @throws ManifestException|JsonException ошибка конфигурации CMS-модуля
     */
    public function fromPackage(DiscoveredPackage $package): ModuleManifest
    {
        if (!$package->isModule()) {
            throw new ManifestException(
                "Пакет '{$package->composerName}' не объявлен модулем (extra.bescms.kind должен быть 'module')."
            );
        }

        if ($package->moduleClass === null || $package->moduleId === null) {
            throw new ManifestException(
                "Пакет '{$package->composerName}' помечен как модуль, но не объявляет extra.moduleClass/moduleId."
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
                "Класс '{$class}' (пакет '{$package->composerName}') ещё не переведён на новый контракт "
                . DeclaresModule::class . ' — модуль не сконвертирован.'
            );
        }

        $id = $class::moduleId();
        if ($id !== $package->moduleId) {
            throw new ManifestException(
                "Несоответствие moduleId: composer.json объявляет '{$package->moduleId}', "
                . "а класс {$class}::moduleId() возвращает '{$id}'."
            );
        }

        $config = $class::moduleConfig();
        $iconClass = (string)($config['params']['iconClass'] ?? '');

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
            config: $config,
            iconClass: $iconClass,
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
