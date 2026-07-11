<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle;

/**
 * Результат операции как DTO — что произошло, предупреждения, ошибки.
 *
 * Сервисный слой НЕ знает про `session flash` (в отличие от старого modman). Драйвер (web/console)
 * сам решает, как показать отчёт. Это делает хендлеры пригодными для web, CLI, очереди и Ansible.
 */
final class OperationReport
{
    private bool $failed = false;

    /** @var string[] выполненные/планируемые шаги */
    private array $steps = [];
    /** @var string[] */
    private array $infos = [];
    /** @var string[] */
    private array $warnings = [];
    /** @var string[] */
    private array $errors = [];

    public function __construct(
        public readonly OperationType $type,
        public readonly string        $moduleId,
    ) {}

    public function step(string $message): void
    {
        $this->steps[] = $message;
    }

    public function info(string $message): void
    {
        $this->infos[] = $message;
    }

    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Зафиксировать ошибку — переводит отчёт в состояние «неуспешно».
     */
    public function error(string $message): void
    {
        $this->errors[] = $message;
        $this->failed = true;
    }

    public function isSuccessful(): bool
    {
        return !$this->failed;
    }

    /** @return string[] */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return string[] */
    public function infos(): array
    {
        return $this->infos;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'moduleId' => $this->moduleId,
            'successful' => $this->isSuccessful(),
            'steps' => $this->steps,
            'infos' => $this->infos,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
