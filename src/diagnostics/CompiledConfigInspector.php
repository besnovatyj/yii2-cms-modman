<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\diagnostics;

use Throwable;
use Yii;

/**
 * Инспектор итогового собранного конфига — для диагностики «что реально попало в приложение».
 *
 * Две проекции на группу конфига:
 *  - {@see contributors()} — «кто вложил»: из merge-plan (`[env][group][package][]=file`), только
 *    активные модули + root, в порядке слияния (vendor < root). Это артефакт самого modman — без
 *    исполнения чужого кода.
 *  - {@see assembled()} — «что вышло»: РЕАЛЬНО собранный конфиг группы тем же движком, что строит
 *    конфиг приложения (`common\config\ConfigFactory` поверх `Yiisoft\Config`), чтобы вьювер
 *    показывал ровно то, что исполняется в рантайме, а не пересобранную копию.
 *
 * Безопасность вывода: секретоподобные ключи маскируются ({@see SECRET_MARKERS}), замыкания и объекты
 * заменяются метками — голый дамп конфига содержал бы пароли БД/redis, токены, hmac-ключи.
 */
final class CompiledConfigInspector
{
    /** Подстроки в имени ключа (lowercase), значение которых маскируется в выводе. */
    private const array SECRET_MARKERS = [
        'password', 'passwd', 'secret', 'token', 'hmac', 'dsn', 'apikey', 'api_key',
        'privatekey', 'private_key', 'salt', 'cookievalidationkey', 'credential',
        'authkey', 'auth_key', 'accesskey', 'access_key',
    ];

    private const string MASK = '••• скрыто •••';

    /**
     * @param string $mergePlanFile абсолютный путь к var/config/merge-plan.php
     * @param string $env           окружение yiisoft/config (у нас единственное — '/')
     */
    public function __construct(
        private readonly string $mergePlanFile,
        private readonly string $env = '/',
    ) {}

    /**
     * Доступные для просмотра группы (ключи merge-plan текущего окружения), отсортированы.
     *
     * @return string[]
     */
    public function groups(): array
    {
        $plan = $this->mergePlan();
        $groups = array_keys($plan[$this->env] ?? []);
        sort($groups);
        return $groups;
    }

    /**
     * «Кто вкладывает» в группу: package => список файлов, в порядке слияния.
     *
     * Разворачивает ссылки на другие группы (`$common` и т.п.): app-группы КОМПОЗИТНЫ — у root-пакета
     * первым идёт `$common`, т.е. в `app-backend`/`app-frontend` вливается вся группа `common` (а с ней
     * и компоненты вроде `shortcode`, `authManager`), а уже потом app-специфичные файлы. Без разворота
     * список «кто вложил» не сходился бы с итоговым конфигом. Рекурсия защищена от циклов.
     *
     * @param array<string,true> $seen внутренний guard от циклов ссылок
     * @return array<string, string[]>
     */
    public function contributors(string $group, array $seen = []): array
    {
        if (isset($seen[$group])) {
            return [];
        }
        $seen[$group] = true;

        $raw = $this->mergePlan()[$this->env][$group] ?? [];
        $out = [];
        foreach ($raw as $package => $files) {
            foreach ($files as $file) {
                if (is_string($file) && str_starts_with($file, '$')) {
                    // Ссылка на другую группу — вливаем её вкладчиков сюда же.
                    foreach ($this->contributors(substr($file, 1), $seen) as $refPackage => $refFiles) {
                        foreach ($refFiles as $refFile) {
                            $out[$refPackage][] = $refFile;
                        }
                    }
                } else {
                    $out[$package][] = $file;
                }
            }
        }
        foreach ($out as $package => $files) {
            $out[$package] = array_values(array_unique($files));
        }
        return $out;
    }

    /**
     * «Что вышло»: собранный конфиг группы, очищенный для показа (секреты/замыкания/объекты).
     * Возвращает либо массив, либо строку-ошибку (если движок сборки недоступен/упал).
     *
     * @return array<mixed>|string
     */
    public function assembled(string $group): array|string
    {
        $factoryClass = '\\common\\config\\ConfigFactory';
        if (!class_exists($factoryClass)) {
            return 'Движок сборки (common\\config\\ConfigFactory) недоступен — показать собранный конфиг нельзя.';
        }

        try {
            /** @var object $factory */
            $factory = new $factoryClass();
            /** @var array $config */
            $config = $factory->get($group);
        } catch (Throwable $e) {
            Yii::warning("assembled('{$group}'): {$e->getMessage()}", 'modman/diagnostics');
            return 'Ошибка сборки группы: ' . $e->getMessage();
        }

        return $this->sanitize($config);
    }

    /**
     * Рекурсивно готовит значение к показу: маскирует секреты по имени ключа, заменяет замыкания и
     * объекты читаемыми метками (иначе дамп упадёт/утечёт).
     */
    private function sanitize(mixed $value, string $key = ''): mixed
    {
        if ($this->isSecretKey($key)) {
            return self::MASK;
        }
        if ($value instanceof \Closure) {
            return '<Closure>';
        }
        if (is_object($value)) {
            return '<object ' . $value::class . '>';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->sanitize($v, is_string($k) ? $k : '');
            }
            return $out;
        }
        return $value;
    }

    private function isSecretKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        $lower = strtolower($key);
        foreach (self::SECRET_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, array<string, array<string, string[]>>>
     */
    private function mergePlan(): array
    {
        if (!is_file($this->mergePlanFile)) {
            return [];
        }
        /** @var mixed $plan */
        $plan = require $this->mergePlanFile;
        return is_array($plan) ? $plan : [];
    }
}
