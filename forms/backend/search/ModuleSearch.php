<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\forms\backend\search;

use modules\modmanNew\catalog\source\DiscoveredPackage;
use modules\modmanNew\ModuleView;
use yii\base\Model;

/**
 * Простой поиск по списку модулей/пакетов (фильтр по id/имени/описанию).
 *
 * Работает поверх уже собранных коллекций ({@see ModuleView}/{@see DiscoveredPackage}) — без запросов
 * в БД: данные приходят из каталога/реестра.
 */
final class ModuleSearch extends Model
{
    public ?string $q = null;

    public function rules(): array
    {
        return [
            [['q'], 'string'],
            [['q'], 'trim'],
        ];
    }

    /**
     * @param ModuleView[] $views
     * @return ModuleView[]
     */
    public function filterModules(array $views): array
    {
        $q = trim((string)$this->q);
        if ($q === '') {
            return $views;
        }

        return array_values(array_filter(
            $views,
            static fn(ModuleView $v): bool => stripos($v->id, $q) !== false || stripos($v->package, $q) !== false,
        ));
    }

    /**
     * @param DiscoveredPackage[] $packages
     * @return DiscoveredPackage[]
     */
    public function filterPackages(array $packages): array
    {
        $q = trim((string)$this->q);
        if ($q === '') {
            return $packages;
        }

        return array_values(array_filter(
            $packages,
            static fn(DiscoveredPackage $p): bool =>
                stripos($p->composerName, $q) !== false
                || stripos($p->description, $q) !== false
                || ($p->moduleId !== null && stripos($p->moduleId, $q) !== false),
        ));
    }
}
