<?php

namespace App\Enum;

/** Уровни иерархии локаций (п. 15 ТЗ). */
enum LocationType: string
{
    case Country = 'country';
    case City = 'city';
    case BusinessCenter = 'business_center';
    case Office = 'office';
    case Floor = 'floor';
    case Room = 'room';

    public function label(): string
    {
        return match ($this) {
            self::Country => 'Страна',
            self::City => 'Город',
            self::BusinessCenter => 'Бизнес-центр',
            self::Office => 'Офис',
            self::Floor => 'Этаж',
            self::Room => 'Помещение',
        };
    }

    public function level(): int
    {
        return array_search($this, self::cases(), true);
    }

    /** @return list<self> допустимые типы дочерних узлов */
    public function childTypes(): array
    {
        return array_slice(self::cases(), $this->level() + 1);
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
