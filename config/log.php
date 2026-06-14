<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Канал логирования новой системы управления модулями.
 *
 * Отдаётся через {@see \modules\modmanNew\Module::logChannels()} (контракт ProvidesLogChannels);
 * при компиляции попадает в артефакт logChannels и подмешивается в `log.targets`.
 *
 * Ключ массива — id таргета Yii (он же имя Monolog-канала). Ловит категорию `modmanNew/*`. Сюда
 * пишется служебная диагностика discovery (несконвертированные/невалидные модули, дубликаты) —
 * чтобы не «кричать» уведомлениями в браузере, а складывать в лог. Пример:
 *   `Yii::warning('Текст', 'modmanNew/discovery');`
 *
 * Файл (конвенция вьювера): @runtime/logs/monolog-modmanNew.log
 */

return [
    'modmanNew' => [
        'class' => \common\components\log\MonologTarget::class,
        'channel' => 'modmanNew',
        'categories' => ['modmanNew/*'],
        // level 'debug': менеджер логирует шаги lifecycle через Yii::info/debug; порог по умолчанию
        // 'notice' их бы отбросил. Канал должен показывать все шаги, а не только ошибки.
        'handlers' => [
            ['type' => 'rotating_file', 'file' => '@runtime/logs/monolog-modmanNew.log', 'maxFiles' => 5, 'level' => 'debug'],
        ],
        'addTimestampToContext' => true,
        'extractExceptionTrace' => true,
    ],
];
