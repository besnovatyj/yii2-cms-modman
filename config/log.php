<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Канал логирования системы управления модулями.
 *
 * Отдаётся через {@see \modules\modman\Module::logChannels()} (контракт ProvidesLogChannels);
 * при компиляции попадает в артефакт logChannels и подмешивается в `log.targets`.
 *
 * Ключ массива — id таргета Yii (он же имя Monolog-канала). Ловит категорию `modman/*`. Сюда
 * пишется служебная диагностика discovery (несконвертированные/невалидные модули, дубликаты) —
 * чтобы не «кричать» уведомлениями в браузере, а складывать в лог. Пример:
 *   `Yii::warning('Текст', 'modman/discovery');`
 *
 * Файл (конвенция вьювера): @runtime/logs/monolog-modman.log
 */

return [
    'modman' => [
        'class' => \common\components\log\MonologTarget::class,
        'channel' => 'modman',
        'categories' => ['modman/*'],
        // level 'debug': менеджер логирует шаги lifecycle через Yii::info/debug; порог по умолчанию
        // 'notice' их бы отбросил. Канал должен показывать все шаги, а не только ошибки.
        'handlers' => [
            ['type' => 'rotating_file', 'file' => '@runtime/logs/monolog-modman.log', 'maxFiles' => 5, 'level' => 'debug'],
        ],
        'addTimestampToContext' => true,
        'extractExceptionTrace' => true,
    ],
];
