<?php

namespace App\Enum;

enum RoomType: string
{
    case Cabinet = 'cabinet';
    case Warehouse = 'warehouse';
    case ServerRoom = 'server';
    case Technical = 'technical';
    case Meeting = 'meeting';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cabinet => 'Кабинет',
            self::Warehouse => 'Склад',
            self::ServerRoom => 'Серверная',
            self::Technical => 'Техническое помещение',
            self::Meeting => 'Переговорная',
            self::Other => 'Другое',
        };
    }

    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }

        return $out;
    }
}
