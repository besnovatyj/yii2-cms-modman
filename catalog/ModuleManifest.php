<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\catalog;

use modules\modmanNew\registry\Version;

/**
 * Единый неизменяемый носитель метаданных пакета-модуля.
 *
 * Это центральная модель новой архитектуры: всё, что менеджеру нужно знать о модуле для
 * планирования и компиляции, лежит здесь. Манифест собирается {@see ManifestFactory} один раз и
 * далее передаётся по слоям как value-объект — без повторного опроса класса модуля и без
 * инстанцирования Yii-модуля.
 */
final readonly class ModuleManifest
{
    /**
     * @param string         $id           идентификатор модуля (ключ в Yii `modules`)
     * @param string         $package      composer-имя пакета (например, 'besnovatyj/yii2-cms-blog-new')
     * @param class-string   $moduleClass  FQCN класса модуля
     * @param Version        $version      версия модуля
     * @param bool           $editable     управляем ли модуль через менеджер
     * @param array          $config       базовая конфигурация Yii-модуля (id, params, ...)
     * @param string         $iconClass    CSS-класс иконки модуля (из config.params.iconClass) для списка
     * @param Requirements   $requirements зависимости
     * @param Contributions  $contributions вклады в приложение
     * @param string         $path         абсолютный путь к директории пакета
     * @param string         $checksum     контрольная сумма манифеста (фиксируется в реестре)
     */
    public function __construct(
        public string        $id,
        public string        $package,
        public string        $moduleClass,
        public Version       $version,
        public bool          $editable,
        public array         $config,
        public string        $iconClass,
        public Requirements  $requirements,
        public Contributions $contributions,
        public string        $path,
        public string        $checksum,
    ) {}

    /**
     * Конфигурация модуля для записи в артефакт `modules`: класс + базовый конфиг + версия.
     * Детерминирована — ключи отсортированы, чтобы компиляция была побайтово воспроизводимой.
     */
    public function compiledModuleConfig(): array
    {
        $config = array_merge(
            ['class' => $this->moduleClass],
            $this->config,
            ['version' => $this->version->value],
        );
        ksort($config);
        return $config;
    }
}
