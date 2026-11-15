<?php

namespace App\Service;

use App\Exception\BusinessException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Файловое хранилище вне public-каталога (п. 3, 18.2 ТЗ).
 * Проверка расширения и MIME-типа, ограничение размера, случайные имена без расширения
 * исполняемых типов.
 */
class FileStorage
{
    public const ATTACHMENT_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    public const PLAN_TYPES = [
        'svg' => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'],
        'png' => ['image/png'],
    ];

    public const IMPORT_TYPES = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2'],
    ];

    public function __construct(
        #[Autowire('%env(STORAGE_DIR)%')]
        private readonly string $root,
        #[Autowire('%env(int:UPLOAD_MAX_BYTES)%')]
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @param array<string, list<string>> $allowed
     *
     * @return array{path: string, mime: string, size: int, sha256: string, name: string, ext: string}
     */
    public function storeUpload(UploadedFile $file, string $subdir, array $allowed): array
    {
        if (!$file->isValid()) {
            throw new BusinessException('Ошибка загрузки файла: ' . $file->getErrorMessage());
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new BusinessException(sprintf('Размер файла превышает %d МБ', intdiv($this->maxBytes, 1024 * 1024)));
        }
        $ext = mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            throw new BusinessException('Недопустимый тип файла. Разрешены: ' . implode(', ', array_keys($allowed)));
        }
        $mime = (string) $file->getMimeType();
        if (!in_array($mime, $allowed[$ext], true)) {
            throw new BusinessException(sprintf('Содержимое файла (%s) не соответствует расширению .%s', $mime, $ext));
        }
        if ($ext === 'svg') {
            $this->assertSafeSvg((string) file_get_contents($file->getPathname()));
        }

        $rel = sprintf('%s/%s/%s.%s', trim($subdir, '/'), date('Y/m'), Uuid::v4()->toRfc4122(), $ext);
        $abs = $this->absolute($rel);
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0770, true);
        }
        $sha = hash_file('sha256', $file->getPathname());
        $file->move(dirname($abs), basename($abs));
        @chmod($abs, 0640);

        return ['path' => $rel, 'mime' => $mime, 'size' => $size, 'sha256' => $sha, 'name' => $file->getClientOriginalName(), 'ext' => $ext];
    }

    public function write(string $rel, string $content): string
    {
        $abs = $this->absolute($rel);
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0770, true);
        }
        file_put_contents($abs, $content, LOCK_EX);
        @chmod($abs, 0640);

        return hash('sha256', $content);
    }

    public function read(string $rel): string
    {
        $abs = $this->absolute($rel);
        if (!is_file($abs)) {
            throw new BusinessException('Файл не найден в хранилище');
        }

        return (string) file_get_contents($abs);
    }

    public function absolute(string $rel): string
    {
        if (str_contains($rel, '..')) {
            throw new \InvalidArgumentException('Недопустимый путь');
        }

        return rtrim($this->root, '/') . '/' . ltrim($rel, '/');
    }

    public function delete(string $rel): void
    {
        $abs = $this->absolute($rel);
        if (is_file($abs)) {
            unlink($abs);
        }
    }

    /** SVG не должен содержать скриптов и внешних ссылок. */
    private function assertSafeSvg(string $svg): void
    {
        if (!str_contains($svg, '<svg')) {
            throw new BusinessException('Файл не является SVG');
        }
        if (preg_match('/<script|on[a-z]+\s*=|javascript:|<foreignObject|<!ENTITY/i', $svg)) {
            throw new BusinessException('SVG содержит скрипты или активное содержимое — загрузка запрещена');
        }
    }
}
