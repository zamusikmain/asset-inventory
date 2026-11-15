<?php

namespace App\Service\Integration;

use App\Entity\IntegrationEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Временный коннектор — файловый обмен: каждое событие пишется JSON-файлом
 * в каталог обмена, откуда его забирает обработка 1С.
 */
class FileOneCConnector implements OneCConnectorInterface
{
    public function __construct(
        #[Autowire('%env(STORAGE_DIR)%/exchange/1c/out')]
        private readonly string $dir,
    ) {
    }

    public function send(IntegrationEvent $event): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Не удалось создать каталог обмена ' . $this->dir);
        }
        $file = sprintf('%s/%s_%s_%s.json', $this->dir, $event->getCreatedAt()->format('Ymd_His'), $event->getId(), str_replace('.', '_', $event->getEventType()));
        $json = json_encode([
            'id' => $event->getId(),
            'type' => $event->getEventType(),
            'entity' => $event->getEntityType(),
            'entity_id' => $event->getEntityId(),
            'payload' => $event->getPayload(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Ошибка записи ' . $file);
        }
    }

    public function name(): string
    {
        return 'file';
    }
}
