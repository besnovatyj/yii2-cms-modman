<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog\check;

use Besnovatyj\Modman\catalog\ModuleManifest;
use Besnovatyj\Modman\catalog\source\DiscoveredPackage;

/**
 * Предупреждение: это модуль (`kind=module`), а не просто пакет, но объявляет L1-bootstrap.
 *
 * `extra.bootstrap` подхватывается yii2-composer (`vendor/yiisoft/extensions.php`) и выполняется
 * КАЖДЫЙ запрос, пока пакет установлен composer'ом — независимо от статуса модуля в реестре.
 * Деактивация модуля в менеджере такой Bootstrap НЕ отключает: слушатели событий и URL-правила
 * продолжают работать против выключенного модуля. Конвенция: у module-пакетов вся bootstrap-логика
 * живёт в L2 (вклад `'bootstrap' => [...]` в config-plugin, гейт modman); L1 допустим только для
 * того, что ОБЯЗАНО жить вне гейта (например, подписка на фазы lifecycle самого менеджера — L2
 * неактивного модуля не загружен и не может слушать собственную установку) — такие пакеты
 * перечисляются в allowlist.
 */
final readonly class L1BootstrapCheck implements ModuleWarningCheck
{
    /**
     * @param string[] $allowedPackages composer-имена module-пакетов, которым L1 разрешён осознанно
     */
    public function __construct(
        private array $allowedPackages = [],
    ) {}

    /**
     * {@inheritdoc}
     */
    public function check(DiscoveredPackage $package, ModuleManifest $manifest): ?string
    {
        if ($package->composerBootstrap === [] || in_array($package->composerName, $this->allowedPackages, true)) {
            return null;
        }

        return sprintf(
            'L1-bootstrap (extra.bootstrap: %s) выполняется каждый запрос вне гейта менеджера — '
            . "деактивация модуля его не отключит. Перенесите логику в L2 ('bootstrap' в config-plugin).",
            implode(', ', $package->composerBootstrap),
        );
    }
}
