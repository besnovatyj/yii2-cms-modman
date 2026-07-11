<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman;

use Throwable;
use Yii;
use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Глобальный bootstrap менеджера.
 *
 * Регистрирует DI-проводку (в т.ч. синглтон {@see \Besnovatyj\Modman\events\ModuleLifecycleDispatcher})
 * ДО инициализации остальных модулей — чтобы другие модули могли подписаться на фазы lifecycle в
 * своих Bootstrap. Плюс саморегистрирует сам модуль {@see registerModule()} и канал лога
 * {@see registerLogChannels()} — чтобы менеджер работал независимо от компилируемых им же артефактов.
 * Должен быть добавлен в app bootstrap (см. README).
 */
final class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        (require __DIR__ . '/config/container.php')(Yii::$container);
        $this->registerModule($app);
        $this->registerLogChannels($app);
    }

    /**
     * Саморегистрация модуля менеджера в приложении — из bootstrap, а НЕ из компилируемого
     * артефакта `modulesConfigFile.php`.
     *
     * Это разрывает chicken-and-egg: менеджер компилирует артефакт модулей, поэтому не имеет права
     * зависеть от него, чтобы вообще запуститься. Иначе пустой/устаревший артефакт (свежая установка,
     * переезд, смена id/namespace) блокирует единственный инструмент, которым его можно пересобрать —
     * а руками артефакт править нельзя (закон compile-not-patch).
     *
     * Bootstrap выполняется в конце `Application::init()`, когда `modules` из артефакта уже применены,
     * поэтому guard {@see Module::hasModule()} делает регистрацию идемпотентной: если артефакт уже
     * содержит `Modman` — не трогаем; если нет (или там мёртвый старый id) — регистрируем сами. Как
     * системный модуль (`editable=false`) он всё равно попадёт в артефакт при следующей recompile;
     * саморегистрация — постоянная страховка, а не разовый костыль.
     */
    private function registerModule(Application $app): void
    {
        $id = Module::moduleId();
        if ($app->hasModule($id)) {
            return;
        }
        $config = Module::moduleConfig();
        $config['class'] = Module::class;
        $app->setModule($id, $config);
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
