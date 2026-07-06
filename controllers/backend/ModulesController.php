<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\controllers\backend;

use modules\modman\forms\backend\search\ModuleSearch;
use modules\modman\lifecycle\OperationReport;
use modules\modman\lifecycle\OperationType;
use modules\modman\ModuleManager;
use modules\modman\widgets\OperationReportModal;
use Throwable;
use Yii;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;

/**
 * Backend-интерфейс системы управления модулями.
 *
 * Контроллёр тонкий: вся логика — в {@see ModuleManager} и хендлерах. Здесь только разбор запроса,
 * показ {@see OperationReport} через flash и рендер. Мутации — только POST (с CSRF из форм).
 */
final class ModulesController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly ModuleManager $manager,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'install' => ['POST'],
                    'uninstall' => ['POST'],
                    'update' => ['POST'],
                    'reconcile' => ['POST'],
                    'recompile' => ['POST'],
                    'rebuild-menus' => ['POST'],
                ],
            ],
        ]);
    }

    public function actionIndex(): string
    {
        $search = new ModuleSearch();
        $search->load(Yii::$app->request->queryParams);

        foreach ($this->manager->warnings() as $warning) {
            Yii::$app->session->addFlash('warning', $warning);
        }

        return $this->render('index', [
            'search' => $search,
            'dataProvider' => $search->moduleDataProvider($this->manager->modules()),
            'packages' => $search->filterPackages($this->manager->packages()),
            'pending' => $this->manager->pending(),
        ]);
    }

    public function actionCheck(string $moduleId, string $op = ''): string
    {
        $plan = $op === 'uninstall'
            ? $this->manager->checkUninstall($moduleId)
            : $this->manager->check($moduleId);

        return $this->render('plan', ['plan' => $plan]);
    }

    public function actionInstall(): Response
    {
        $this->flashReport($this->manager->install($this->requireModuleId()));
        return $this->redirect(['index']);
    }

    public function actionUninstall(): Response
    {
        $this->flashReport($this->manager->uninstall($this->requireModuleId()));
        return $this->redirect(['index']);
    }

    public function actionUpdate(): Response
    {
        $this->flashReport($this->manager->update($this->requireModuleId()));
        return $this->redirect(['index']);
    }

    public function actionReconcile(): Response
    {
        $this->flashReport($this->manager->reconcile());
        return $this->redirect(['index']);
    }

    public function actionRecompile(): Response
    {
        $this->flashArtifacts('Пересборка конфигурации', 'Конфигурация перекомпилирована из реестра.', 'Ошибка перекомпиляции: ', fn() => $this->manager->recompile()->warnings);
        return $this->redirect(['index']);
    }

    public function actionRebuildMenus(): Response
    {
        $this->flashArtifacts('Пересборка меню', 'Меню перекомпилировано из реестра.', 'Ошибка пересборки меню: ', fn() => $this->manager->recompileMenus()->warnings);
        return $this->redirect(['index']);
    }

    /**
     * Достаёт обязательный moduleId из тела POST-запроса (формы кладут его в hiddenInput).
     *
     * Важно: web-контроллёр Yii биндит аргументы экшена из query-параметров, а не из тела POST,
     * поэтому мутирующие действия читают moduleId отсюда, а не через параметр метода.
     *
     * @throws BadRequestHttpException если параметр отсутствует
     */
    private function requireModuleId(): string
    {
        $moduleId = (string)Yii::$app->request->post('moduleId', '');
        if ($moduleId === '') {
            throw new BadRequestHttpException('Отсутствует обязательный параметр moduleId.');
        }
        return $moduleId;
    }

    /**
     * Перекладывает отчёт операции в отчёт-модалку (UI-агностичный сервис → представление).
     *
     * Раньше отчёт разворачивался в поток flash-алертов, которые из-за объёма шагов уезжали за
     * границу экрана. Теперь сообщения группируются и показываются в модальном окне
     * {@see OperationReportModal}.
     */
    private function flashReport(OperationReport $report): void
    {
        $groups = [];
        if ($report->steps() !== []) {
            $groups[] = ['label' => 'Шаги', 'variant' => 'secondary', 'items' => $report->steps()];
        }
        if ($report->infos() !== []) {
            $groups[] = ['label' => 'Информация', 'variant' => $report->isSuccessful() ? 'success' : 'info', 'items' => $report->infos()];
        }
        if ($report->warnings() !== []) {
            $groups[] = ['label' => 'Предупреждения', 'variant' => 'warning', 'items' => $report->warnings()];
        }
        if ($report->errors() !== []) {
            $groups[] = ['label' => 'Ошибки', 'variant' => 'danger', 'items' => $report->errors()];
        }

        $this->flashModal($this->operationTitle($report->type, $report->moduleId), $report->isSuccessful(), $groups);
    }

    /**
     * Выполняет операцию пересборки артефактов и кладёт её результат в отчёт-модалку.
     *
     * @param callable(): string[] $run операция, возвращающая список предупреждений артефактов
     */
    private function flashArtifacts(string $title, string $successMessage, string $errorPrefix, callable $run): void
    {
        try {
            $warnings = $run();
            $groups = [['label' => 'Результат', 'variant' => 'success', 'items' => [$successMessage]]];
            if ($warnings !== []) {
                $groups[] = ['label' => 'Предупреждения', 'variant' => 'warning', 'items' => $warnings];
            }
            $this->flashModal($title, true, $groups);
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            $this->flashModal($title, false, [
                ['label' => 'Ошибки', 'variant' => 'danger', 'items' => [$errorPrefix . $e->getMessage()]],
            ]);
        }
    }

    /**
     * Кладёт структурированный отчёт во flash, который читает {@see OperationReportModal}.
     *
     * @param array<int, array{label: string, variant: string, items: string[]}> $groups
     */
    private function flashModal(string $title, bool $ok, array $groups): void
    {
        Yii::$app->session->setFlash(OperationReportModal::FLASH_KEY, [
            'title' => $title,
            'ok' => $ok,
            'groups' => $groups,
        ]);
    }

    /**
     * Человекочитаемый заголовок модалки по типу операции и id модуля.
     */
    private function operationTitle(OperationType $type, string $moduleId): string
    {
        $label = match ($type) {
            OperationType::Install => 'Установка',
            OperationType::Uninstall => 'Удаление',
            OperationType::Update => 'Обновление',
            OperationType::Reconcile => 'Сверка состояния',
            OperationType::Check => 'Проверка',
        };

        return $moduleId !== '' ? "{$label}: {$moduleId}" : $label;
    }
}
