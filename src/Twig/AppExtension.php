<?php

namespace App\Twig;

use App\Enum\AssetStatus;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('status_badge', [$this, 'statusBadge'], ['is_safe' => ['html']]),
            new TwigFilter('filesize', [$this, 'fileSize']),
            new TwigFilter('repeat', fn (?string $s, int $n) => str_repeat((string) $s, max(0, $n))),
            new TwigFilter('json_pretty', fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            new TwigFilter('dt', fn (?\DateTimeInterface $d, string $f = 'd.m.Y H:i') => $d ? \DateTimeImmutable::createFromInterface($d)->setTimezone(new \DateTimeZone('Asia/Almaty'))->format($f) : '—'),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset_statuses', fn () => AssetStatus::cases()),
            new TwigFunction('badge', [$this, 'badge'], ['is_safe' => ['html']]),
        ];
    }

    public function statusBadge(AssetStatus|string|null $s): string
    {
        if (is_string($s)) {
            $s = AssetStatus::tryFrom($s);
        }
        if (!$s) {
            return '<span class="badge gray">—</span>';
        }

        return sprintf('<span class="badge %s">%s</span>', $s->color(), htmlspecialchars($s->label()));
    }

    public function badge(?string $label, string $color = 'gray'): string
    {
        return sprintf('<span class="badge %s">%s</span>', htmlspecialchars($color), htmlspecialchars((string) $label));
    }

    public function fileSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' МБ',
            $bytes >= 1024 => round($bytes / 1024) . ' КБ',
            default => $bytes . ' Б',
        };
    }
}
