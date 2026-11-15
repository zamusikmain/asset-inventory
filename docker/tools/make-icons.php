<?php
// Генерация PNG-иконок PWA (192/512) — штрихкод на фирменном фоне.
foreach ([192, 512] as $s) {
    $img = imagecreatetruecolor($s, $s);
    imagefill($img, 0, 0, imagecolorallocate($img, 31, 78, 121));
    $white = imagecolorallocate($img, 255, 255, 255);
    $x = (int) ($s * 0.19);
    foreach ([18, 9, 27, 9, 18, 27, 9, 18, 9, 20] as $bw) {
        $w = max(1, (int) round($bw * $s / 512));
        imagefilledrectangle($img, $x, (int) ($s * 0.29), $x + $w, (int) ($s * 0.71), $white);
        $x += $w + (int) round(17 * $s / 512);
    }
    imagepng($img, __DIR__ . "/../../public/icons/icon-$s.png");
}
echo "ok\n";
