<?php

namespace App\Service;

use App\Entity\Asset;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Этикетки для Brother QL-810W (п. 10.2 ТЗ). Первая версия — PDF для печати
 * через установленный драйвер. Формат по умолчанию: лента DK-22205 62 мм,
 * этикетка 62×29 мм (размер и шаблон уточняются — п. 24 ТЗ).
 */
class LabelService
{
    public const FORMATS = [
        '62x29' => ['w' => 62, 'h' => 29, 'title' => '62 × 29 мм (DK-11209 / DK-22205)'],
        '62x40' => ['w' => 62, 'h' => 40, 'title' => '62 × 40 мм (DK-22205)'],
        '29x90' => ['w' => 90, 'h' => 29, 'title' => '29 × 90 мм (DK-11201)'],
    ];

    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly string $orgName,
    ) {
    }

    public function barcodePng(string $code, int $widthFactor = 2, int $height = 60): string
    {
        return (new BarcodeGeneratorPNG())->getBarcode($code, BarcodeGeneratorPNG::TYPE_CODE_128, $widthFactor, $height);
    }

    public function barcodeSvg(string $code, int $widthFactor = 2, int $height = 50): string
    {
        return (new BarcodeGeneratorSVG())->getBarcode($code, BarcodeGeneratorSVG::TYPE_CODE_128, $widthFactor, $height);
    }

    /** @param list<Asset> $assets */
    public function labelsPdf(array $assets, string $format = '62x29', int $copies = 1): string
    {
        $f = self::FORMATS[$format] ?? self::FORMATS['62x29'];
        $mm = 72 / 25.4;
        $labels = [];
        foreach ($assets as $a) {
            for ($i = 0; $i < max(1, $copies); ++$i) {
                $labels[] = [
                    'org' => $this->orgName,
                    'inv' => $a->getInventoryNumber(),
                    'name' => $a->getShortName(),
                    'barcode' => base64_encode($this->barcodePng($a->getBarcode(), 3, 80)),
                ];
            }
        }

        return $this->pdf->render('pdf/labels.html.twig', ['labels' => $labels, 'w' => $f['w'], 'h' => $f['h']], [$f['w'] * $mm, $f['h'] * $mm]);
    }
}
