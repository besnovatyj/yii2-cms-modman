<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\widgets;

use Yii;
use yii\base\Widget;
use yii\web\View;

/**
 * Показывает отчёт операции управления модулем (установка/удаление/обновление) в модальном
 * окне Bootstrap 5 вместо потока flash-алертов.
 *
 * Контроллёр кладёт структурированный отчёт во flash-ключ {@see self::FLASH_KEY} — этого ключа
 * НЕТ в списке типов глобального `\Besnovatyj\Alert\Widget`, поэтому дубля алертов не будет.
 * Если отчёта во flash нет — виджет ничего не рендерит.
 *
 * Ожидаемая структура flash-данных:
 * ```
 * [
 *   'title'  => string,          // заголовок модалки
 *   'ok'     => bool,            // успех операции (цвет шапки)
 *   'groups' => [                // секции сообщений (пустые группы контроллёр не кладёт)
 *     ['label' => string, 'variant' => string, 'items' => string[]],
 *     ...
 *   ],
 * ]
 * ```
 */
final class OperationReportModal extends Widget
{
    /** Ключ flash-сообщения с отчётом операции. */
    public const string FLASH_KEY = 'modman-report';

    /** DOM-id модального окна (используется и для авто-открытия из JS). */
    private const string MODAL_ID = 'modman-report-modal';

    public function run(): string
    {
        $report = Yii::$app->session->getFlash(self::FLASH_KEY);
        if (!is_array($report) || ($report['groups'] ?? []) === []) {
            return '';
        }

        $this->registerAutoShow();

        return $this->render('operation-report-modal', [
            'modalId' => self::MODAL_ID,
            'title' => (string)($report['title'] ?? 'Результат операции'),
            'ok' => (bool)($report['ok'] ?? true),
            'groups' => $report['groups'],
        ]);
    }

    /**
     * Регистрирует авто-открытие модалки после загрузки страницы.
     *
     * `window.bootstrap` подключается глобально в бэкенд-лейауте (FooterAssets), поэтому
     * достаточно дождаться готовности DOM.
     */
    private function registerAutoShow(): void
    {
        $id = self::MODAL_ID;
        $js = <<<JS
            (function () {
                var el = document.getElementById('{$id}');
                if (el && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(el).show();
                }
            })();
            JS;

        $this->view->registerJs($js, View::POS_READY);
    }
}
