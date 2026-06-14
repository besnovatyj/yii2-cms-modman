<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\catalog\source;

/**
 * Маркер принадлежности пакета к данной CMS — блок `extra.bescms` в composer.json.
 *
 * Зачем: после переезда в GitHub пакеты CMS будут лежать в `vendor/` вперемешку с сотней чужих
 * зависимостей, и фильтрация «по директории» (как в старом modman) перестанет работать. Маркер —
 * единственный устойчивый способ сказать «это пакет моей CMS», не трогая `type: yii2-extension`
 * (по нему Yii подключает свои bootstrap, его менять нельзя).
 *
 * Формат (объект с явными параметрами — меньше магии в проекте):
 * ```json
 * "extra": {
 *     "bescms": { "kind": "module" }
 * }
 * ```
 */
final readonly class CmsMarker
{
    public function __construct(
        public CmsKind $kind,
    ) {}

    /**
     * Читает маркер из блока `extra` composer.json. Возвращает null, если пакет не помечен как CMS.
     *
     * Маркер считается присутствующим, только если `extra.bescms` — объект. Нераспознанный `kind`
     * деградирует до {@see CmsKind::Package}: CMS-пакет не теряется молча, а в худшем случае
     * показывается как обычный пакет (без кнопки установки) — опечатку видно по отсутствию модуля.
     *
     * @param array<string, mixed> $extra
     */
    public static function fromExtra(array $extra): ?self
    {
        $raw = $extra['bescms'] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $kind = (isset($raw['kind']) && is_string($raw['kind']))
            ? CmsKind::tryFrom($raw['kind'])
            : null;

        return new self($kind ?? CmsKind::Package);
    }
}
