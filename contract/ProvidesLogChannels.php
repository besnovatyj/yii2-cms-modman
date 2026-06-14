<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\contract;

/**
 * Модуль регистрирует каналы логирования.
 *
 * Формат: ['<channelId>' => [...спека MonologTarget...]]. В отличие от компонентов, каналы делят
 * один компонент `log`, поэтому компилятор мёржит их по id канала.
 */
interface ProvidesLogChannels
{
    /**
     * @return array<string, array> channelId => спека канала
     */
    public static function logChannels(): array;
}
