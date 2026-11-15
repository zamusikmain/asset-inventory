<?php

namespace App\Enum;

/** Результат сканирования / проверки (п. 10.1 и 12.2 ТЗ). */
enum ScanResult: string
{
    case FoundExpected = 'found_expected';
    case FoundOtherRoom = 'found_other_room';
    case FoundOtherOffice = 'found_other_office';
    case NotFound = 'not_found';
    case Unknown = 'unknown';
    case Damaged = 'damaged';
    case LabelDamaged = 'label_damaged';
    case NeedsClarification = 'clarify';
    case WrittenOffFound = 'written_off_found';
    case Duplicate = 'duplicate';
    case InvalidCode = 'invalid_code';

    public function label(): string
    {
        return match ($this) {
            self::FoundExpected => 'Найден на ожидаемом месте',
            self::FoundOtherRoom => 'Найден в другом помещении',
            self::FoundOtherOffice => 'Найден в другом офисе',
            self::NotFound => 'Не найден',
            self::Unknown => 'Найден неизвестный объект',
            self::Damaged => 'Поврежден',
            self::LabelDamaged => 'Штрихкод отсутствует или поврежден',
            self::NeedsClarification => 'Требуется уточнение',
            self::WrittenOffFound => 'Обнаружен списанный объект',
            self::Duplicate => 'Повторное сканирование',
            self::InvalidCode => 'Некорректный код',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FoundExpected => 'green',
            self::FoundOtherRoom, self::FoundOtherOffice, self::NeedsClarification, self::LabelDamaged => 'amber',
            self::Duplicate => 'gray',
            default => 'red',
        };
    }

    /** Порождает ли результат расхождение. */
    public function createsDiscrepancy(): bool
    {
        return !in_array($this, [self::FoundExpected, self::Duplicate, self::InvalidCode], true);
    }
}
