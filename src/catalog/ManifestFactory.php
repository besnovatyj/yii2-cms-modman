<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog;

use JsonException;
use Besnovatyj\Modman\catalog\exception\ManifestException;
use Besnovatyj\Modman\catalog\source\DiscoveredPackage;
use Besnovatyj\Contracts\module\DeclaresModule;
use Besnovatyj\Contracts\module\ProvidesAdminMenu;
use Besnovatyj\Contracts\module\ProvidesAppConfig;
use Besnovatyj\Contracts\module\ProvidesBootstrap;
use Besnovatyj\Contracts\module\ProvidesComponents;
use Besnovatyj\Contracts\module\ProvidesDependencies;
use Besnovatyj\Contracts\module\ProvidesDirectories;
use Besnovatyj\Contracts\module\ProvidesLogChannels;
use Besnovatyj\Contracts\module\ProvidesMigrations;
use Besnovatyj\Contracts\module\ProvidesOptions;
use Besnovatyj\Modman\registry\Version;
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
     * Политика пер-аппликационного вклада (контракт {@see ProvidesAppConfig}): allowlist путей-ключей,
     * которые модуль вправе класть в конфиг приложения. Всё, чего здесь нет, вырезается.
     *
     * Значение:
     *  - `true` — ключ разрешён целиком (любой подмассив);
     *  - массив `['подключ' => true, ...]` — разрешены ТОЛЬКО перечисленные подключи, остальные режутся.
     *
     * БЕЗОПАСНОСТЬ: `as access` открыт лишь на `allowActions`. Класс гейта (`as access.class`), его
     * `rules`, `denyCallback` и прочее модулю НЕДОСТУПНЫ — гейт принадлежит ядру, модуль может только
     * ДОПОЛНИТЬ whitelist, но не подменить/снять замок. Это единая точка, где расширяются полномочия
     * модулей на вмешательство в конфиг приложения.
     */
    private const array APP_CONFIG_POLICY = [
        'components' => true,
        'params' => true,
        'as access' => ['allowActions' => true],
    ];

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

        $version = $this->resolveVersion($package, $class);
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
            appConfig: $this->implementsContract($class, ProvidesAppConfig::class) ? $this->sanitizeAppConfig($class::appConfig()) : [],
            configPlugin: $package->configPlugin,
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
            sourceUrl: $package->sourceUrl,
        );
    }

    /**
     * Версия установленного на диске кода — авторитетный источник composer, а НЕ ручная константа.
     *
     * Приоритет:
     *  1. `composer` version из installed.json (реальный git-тег, например `v1.1.4`) — не дрейфует,
     *     обновляется автоматически при `composer update`; смена тега меняет checksum → «обновление»
     *     флагается само;
     *  2. если composer отдаёт dev-ветку (`dev-master` у path/symlink-репо, где тега нет) — синтетика
     *     `0.0.0-dev+<sha7>`: semver-сравнение осмысленно деградирует (dev всегда «ниже» релиза),
     *     а фактическую смену кода ловит checksum по reference;
     *  3. крайний fallback — константа {@see DeclaresModule::moduleVersion()} (нужна лишь когда источник
     *     без composer-метаданных, например голый filesystem-скан без installed.json).
     *
     * @param class-string $class
     */
    private function resolveVersion(DiscoveredPackage $package, string $class): Version
    {
        $composer = trim($package->composerVersion);

        if ($composer !== '' && !str_starts_with($composer, 'dev-')) {
            return new Version($composer);
        }

        if ($package->sourceReference !== '') {
            return new Version('0.0.0-dev+' . $package->shortReference());
        }

        return new Version($class::moduleVersion());
    }

    private function implementsContract(string $class, string $interface): bool
    {
        return isset(class_implements($class)[$interface]);
    }

    /**
     * Пропускает пер-аппликационный вклад модуля через allowlist {@see APP_CONFIG_POLICY}: оставляет
     * только разрешённые ключи (и разрешённые подключи), остальное молча вырезает. Так модуль физически
     * не может подсунуть в конфиг приложения `as access.class` и т.п. — это гарантия на этапе компиляции,
     * а не договорённость.
     *
     * @param array<string, array> $appConfig appId => сырой вклад из {@see ProvidesAppConfig::appConfig()}
     * @return array<string, array> очищенный вклад (пустые приложения отброшены)
     */
    private function sanitizeAppConfig(array $appConfig): array
    {
        $clean = [];
        foreach ($appConfig as $appId => $contribution) {
            if (!is_array($contribution)) {
                continue;
            }
            $bucket = [];
            foreach ($contribution as $key => $value) {
                $rule = self::APP_CONFIG_POLICY[$key] ?? null;
                if ($rule === true) {
                    $bucket[$key] = $value;
                } elseif (is_array($rule) && is_array($value)) {
                    $allowedSub = array_intersect_key($value, $rule);
                    if ($allowedSub !== []) {
                        $bucket[$key] = $allowedSub;
                    }
                }
                // $rule === null → ключ не в allowlist → вырезаем.
            }
            if ($bucket !== []) {
                $clean[(string)$appId] = $bucket;
            }
        }
        return $clean;
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
            'appConfig' => $contributions->appConfig,
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
