<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * HTML (Twig) → PDF через Dompdf. Шрифт DejaVu Sans поддерживает кириллицу и казахский.
 */
class PdfRenderer
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function render(string $template, array $context, string|array $paper = 'A4', string $orientation = 'portrait'): string
    {
        $html = $this->twig->render($template, $context);

        return $this->fromHtml($html, $paper, $orientation);
    }

    /** @param string|array{0: float, 1: float} $paper размер или [ширина, высота] в pt */
    public function fromHtml(string $html, string|array $paper = 'A4', string $orientation = 'portrait'): string
    {
        $opts = new Options();
        $opts->set('defaultFont', 'DejaVu Sans');
        $opts->set('isRemoteEnabled', false);
        $opts->set('isPhpEnabled', false);
        $opts->setChroot([sys_get_temp_dir()]);

        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper(is_array($paper) ? [0, 0, $paper[0], $paper[1]] : $paper, $orientation);
        $pdf->render();

        return (string) $pdf->output();
    }
}
