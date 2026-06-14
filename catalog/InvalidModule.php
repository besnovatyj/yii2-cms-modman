<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\catalog;

/**
 * CMS-пакет, объявивший себя модулем (`extra.bescms.kind=module`), но непригодный к установке.
 *
 * Это НЕ «чужой/старый» пакет (такие отбрасываются ещё в каталоге по отсутствию маркера), а именно
 * наш модуль с проблемой конфигурации: не переведён на новый контракт, нет moduleClass, дубликат id
 * и т.п. Такие записи не выбрасываются молча и не «кричат» flash'ем на всю страницу — они
 * показываются строкой в списке рядом с причиной и погашенной кнопкой установки, а подробности
 * уходят в канал лога `modmanNew/*`.
 */
final readonly class InvalidModule
{
    public function __construct(
        public string  $package,
        public ?string $declaredId,
        public string  $reason,
    ) {}
}
