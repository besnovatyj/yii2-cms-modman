<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\deps;

use modules\modmanNew\catalog\ModuleManifest;
use modules\modmanNew\catalog\PackageCatalog;
use modules\modmanNew\deps\exception\DependencyException;
use modules\modmanNew\registry\ModuleRegistry;
use Yii;

/**
 * Проверка зависимостей и вычисление порядка операций.
 *
 * Закрывает два пробела старого modman: проверка зависимостей при УСТАНОВКЕ и (главное) проверка
 * ОБРАТНЫХ зависимостей при удалении — нельзя удалить модуль, на котором держатся другие.
 */
final class DependencyResolver
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PackageCatalog $catalog,
    ) {}

    /**
     * Убедиться, что модуль можно установить (все зависимости удовлетворены).
     *
     * @throws DependencyException
     */
    public function assertCanInstall(ModuleManifest $manifest): void
    {
        $req = $manifest->requirements;
        $id = $manifest->id;

        if ($req->phpVersion !== null && !SemverConstraint::satisfies(PHP_VERSION, $req->phpVersion)) {
            throw new DependencyException(
                "Модулю '{$id}' нужен PHP {$req->phpVersion}, установлен " . PHP_VERSION . '.'
            );
        }

        if ($req->yiiVersion !== null && !SemverConstraint::satisfies(Yii::getVersion(), $req->yiiVersion)) {
            throw new DependencyException(
                "Модулю '{$id}' нужен Yii {$req->yiiVersion}, установлен " . Yii::getVersion() . '.'
            );
        }

        $missingExt = array_values(array_filter(
            $req->phpExtensions,
            static fn(string $ext): bool => !extension_loaded($ext),
        ));
        if ($missingExt !== []) {
            throw new DependencyException(
                "Модулю '{$id}' нужны PHP-расширения: " . implode(', ', $missingExt) . '.'
            );
        }

        $missingModules = [];
        foreach ($req->modules as $dependency) {
            [$depId, $constraint] = $this->parse($dependency);

            if (!$this->registry->isInstalled($depId)) {
                $missingModules[] = $dependency;
                continue;
            }

            if ($constraint !== null) {
                $installedVersion = $this->registry->get($depId)?->version->value ?? '0.0.0';
                if (!SemverConstraint::satisfies($installedVersion, $constraint)) {
                    throw new DependencyException(
                        "Модулю '{$id}' нужен '{$depId}' версии {$constraint}, установлена {$installedVersion}."
                    );
                }
            }
        }

        if ($missingModules !== []) {
            throw new DependencyException(
                "Модуль '{$id}' зависит от неустановленных модулей: " . implode(', ', $missingModules) . '.'
            );
        }
    }

    /**
     * Убедиться, что модуль можно удалить (нет установленных зависящих от него).
     *
     * @throws DependencyException
     */
    public function assertCanUninstall(string $id): void
    {
        $dependents = $this->installedDependents($id);
        if ($dependents !== []) {
            throw new DependencyException(
                "Нельзя удалить модуль '{$id}': от него зависят установленные модули: "
                . implode(', ', $dependents) . '.'
            );
        }
    }

    /**
     * Установленные модули, которые зависят от $id (обратные зависимости).
     *
     * @return string[]
     */
    public function installedDependents(string $id): array
    {
        $dependents = [];
        foreach ($this->registry->all() as $stateId => $state) {
            if ($stateId === $id || !$state->status->isActive()) {
                continue;
            }
            $manifest = $this->catalog->findById($stateId);
            if ($manifest === null) {
                continue;
            }
            foreach ($manifest->requirements->modules as $dependency) {
                if ($this->parse($dependency)[0] === $id) {
                    $dependents[] = $stateId;
                    break;
                }
            }
        }
        return $dependents;
    }

    /**
     * Топологический порядок установки для набора манифестов (зависимости первыми).
     *
     * @param ModuleManifest[] $manifests
     * @return string[]
     */
    public function installOrder(array $manifests): array
    {
        $ids = array_map(static fn(ModuleManifest $m): string => $m->id, $manifests);
        $idSet = array_flip($ids);

        $edges = [];
        foreach ($manifests as $manifest) {
            $deps = [];
            foreach ($manifest->requirements->modules as $dependency) {
                $depId = $this->parse($dependency)[0];
                if (isset($idSet[$depId])) {
                    $deps[] = $depId;
                }
            }
            $edges[$manifest->id] = $deps;
        }

        return new DependencyGraph($edges)->topologicalOrder();
    }

    /**
     * Разбирает 'id' или 'id:constraint'.
     *
     * @return array{0:string,1:?string}
     */
    private function parse(string $dependency): array
    {
        if (str_contains($dependency, ':')) {
            [$id, $constraint] = explode(':', $dependency, 2);
            return [trim($id), trim($constraint)];
        }
        return [trim($dependency), null];
    }
}
