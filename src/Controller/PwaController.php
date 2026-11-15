<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Мобильная PWA-инвентаризация (п. 11 ТЗ): установка через браузер, offline,
 * сканирование камерой, IndexedDB, синхронизация по UUID операций.
 */
class PwaController extends AbstractController
{
    #[Route('/m', name: 'pwa_app')]
    #[IsGranted('ROLE_USER')]
    public function app(): Response
    {
        return $this->render('pwa/app.html.twig');
    }

    #[Route('/scan', name: 'scan_lookup')]
    #[IsGranted('ROLE_USER')]
    public function lookup(): Response
    {
        return $this->render('scan/lookup.html.twig');
    }

    #[Route('/manifest.webmanifest', name: 'pwa_manifest')]
    public function manifest(): Response
    {
        return $this->json([
            'name' => 'Инвентаризация IT',
            'short_name' => 'Инвентаризация',
            'start_url' => '/m',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => '#1f4e79',
            'lang' => 'ru',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    #[Route('/offline', name: 'pwa_offline')]
    public function offline(): Response
    {
        return $this->render('pwa/offline.html.twig');
    }
}
