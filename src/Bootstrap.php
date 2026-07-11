<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman;

use Throwable;
use Yii;
use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Глобальный bootstrap менеджера.
 *
 * Регистрирует DI-проводку (в т.ч. синглтон {@see \modules\modman\events\ModuleLifecycleDispatcher})
 * ДО инициализации остальных модулей — чтобы другие модули могли подписаться на фазы lifecycle в
 * своих Bootstrap. Должен быть добавлен в app bootstrap (см. README).
 */
final class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        (require __DIR__ . '/config/container.php')(Yii::$container);
        $this->registerLogChannels($app);
    }

    /**
     * Поднимает собственный канал лога менеджера в рантайме.
     *
     * Зачем здесь, а не только через скомпилированный артефакт logChannels: на холодном старте этот
     * артефакт может быть ещё не собран/не подключён, и тогда всё, что пишется в `modman/*` (отчёты
     * установки/удаления, миграции, несоответствия discovery), провалилось бы в общий `monolog.log`
     * вместо своего файла. Каналы берём из {@see Module::logChannels()} — единый источник, тот же, что
     * компилируется в артефакт. Уже зарегистрированный таргет не трогаем.
     */
    private function registerLogChannels(Application $app): void
    {
        if (!$app->has('log')) {
            return;
        }

        $dispatcher = $app->get('log');
        foreach (Module::logChannels() as $id => $spec) {
            if (isset($dispatcher->targets[$id])) {
                continue;
            }
            try {
                $dispatcher->targets[$id] = Yii::createObject($spec);
            } catch (Throwable $e) {
                Yii::warning("Не удалось поднять канал лога '{$id}': {$e->getMessage()}", 'modman/lifecycle');
            }
        }
    }
}
