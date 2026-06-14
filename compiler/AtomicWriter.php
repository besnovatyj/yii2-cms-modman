<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\compiler;

use Besnovatyj\Helpers\ArrayExportHelper;
use RuntimeException;

/**
 * Атомарная запись PHP-файлов-артефактов.
 *
 * Единственная точка записи на диск для реестра и компилятора. Запись идёт во временный файл рядом
 * с целевым, затем `rename()` (атомарно в пределах одной ФС) — без постоянных `*_backup`, которые в
 * старом modman порождали гонки и осиротевшие файлы. Каждая запись инвалидирует opcache.
 */
final class AtomicWriter
{
    public function __construct(
        private readonly ArrayExportHelper $exporter,
    ) {}

    /**
     * Атомарно записать массив как `<?php return [...];`.
     */
    public function writeArray(string $path, array $data): void
    {
        $this->writeRaw($path, $this->exporter->export($data));
    }

    /**
     * Атомарно записать произвольное содержимое файла.
     */
    public function writeRaw(string $path, string $content): void
    {
        $this->ensureDir(dirname($path));

        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException("Не удалось записать временный файл: {$tmp}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Не удалось атомарно заменить файл: {$path}");
        }

        $this->invalidate($path);
    }

    /**
     * Удалить артефакт (если существует) и инвалидировать opcache.
     */
    public function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
            $this->invalidate($path);
        }
    }

    private function invalidate(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать директорию: {$dir}");
        }
    }
}
