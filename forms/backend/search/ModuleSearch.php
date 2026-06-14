<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\forms\backend\search;

use Besnovatyj\Forms\BaseForm;
use modules\modmanNew\catalog\source\DiscoveredPackage;
use modules\modmanNew\ModuleView;
use yii\data\ArrayDataProvider;

/**
 * Поиск/фильтрация списков модулей и пакетов.
 *
 * Работает поверх уже собранных коллекций ({@see ModuleView}/{@see DiscoveredPackage}) — без запросов
 * в БД: данные приходят из каталога/реестра. Для модулей отдаёт {@see ArrayDataProvider} (сортировка
 * колонок + пагинация), пакеты фильтрует в простой массив.
 *
 * Наследует {@see BaseForm}: его setAttributes приводит скаляры к объявленным типам PHP 8 (из GET
 * `updatesOnly` приходит строкой '0'/'1') — без TypeError на типизированных свойствах.
 */
final class ModuleSearch extends BaseForm
{
    public ?string $q = null;

    /** Категория для фильтра: '', installed, discovered, system, invalid, orphan, ... */
    public ?string $status = null;

    /** Показывать только модули с доступным обновлением. */
    public bool $updatesOnly = false;

    public function rules(): array
    {
        return [
            [['q', 'status'], 'string'],
            [['q', 'status'], 'trim'],
            [['updatesOnly'], 'boolean'],
        ];
    }

    public function formName(): string
    {
        return '';
    }

    /**
     * Доступные значения фильтра статуса (для выпадающего списка).
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            '' => 'Все статусы',
            'installed' => 'Установленные',
            'discovered' => 'Доступные к установке',
            'system' => 'Системные',
            'invalid' => 'Невалидные',
            'orphan' => 'Осиротевшие',
        ];
    }

    /**
     * Провайдер модулей: применяет фильтры, затем даёт сортировку колонок и пагинацию.
     *
     * @param ModuleView[] $views
     */
    public function moduleDataProvider(array $views): ArrayDataProvider
    {
        return new ArrayDataProvider([
            'allModels' => $this->filterModules($views),
            'sort' => [
                'attributes' => ['id', 'status', 'availableVersion', 'installedVersion'],
                'defaultOrder' => ['id' => SORT_ASC],
            ],
            'pagination' => ['pageSize' => 25],
        ]);
    }

    /**
     * @param ModuleView[] $views
     * @return ModuleView[]
     */
    public function filterModules(array $views): array
    {
        $q = trim((string)$this->q);
        $status = trim((string)$this->status);

        return array_values(array_filter($views, function (ModuleView $v) use ($q, $status): bool {
            if ($q !== '' && stripos($v->id, $q) === false && stripos($v->package, $q) === false) {
                return false;
            }
            if ($status !== '' && $this->category($v) !== $status) {
                return false;
            }
            if ($this->updatesOnly && !$v->hasUpdate) {
                return false;
            }
            return true;
        }));
    }

    /**
     * Категория модуля для фильтра по статусу (флаги имеют приоритет над «сырым» статусом реестра).
     */
    private function category(ModuleView $v): string
    {
        return match (true) {
            $v->invalid => 'invalid',
            $v->orphan => 'orphan',
            $v->system => 'system',
            default => $v->status,
        };
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
