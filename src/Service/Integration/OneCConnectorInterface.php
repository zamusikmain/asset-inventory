<?php

namespace App\Service\Integration;

use App\Entity\IntegrationEvent;

/**
 * Транспорт обмена с 1С. Формат (HTTP-сервис, REST, OData, файлы) определяется
 * после уточнения конфигурации 1С — п. 17, 24 ТЗ.
 */
interface OneCConnectorInterface
{
    /** @throws \Throwable при ошибке доставки */
    public function send(IntegrationEvent $event): void;

    public function name(): string;
}
