<?php

namespace App\Enum;

/**
 * Статусы оборудования (п. 7 ТЗ) и матрица допустимых переходов.
 */
enum AssetStatus: string
{
    case Created = 'created';
    case Review = 'review';
    case InStock = 'in_stock';
    case Reserved = 'reserved';
    case Assigned = 'assigned';
    case Installed = 'installed';
    case InTransit = 'in_transit';
    case Repair = 'repair';
    case TempIssued = 'temp_issued';
    case NotFound = 'not_found';
    case Lost = 'lost';
    case Damaged = 'damaged';
    case WrittenOff = 'written_off';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Создано',
            self::Review => 'На проверке',
            self::InStock => 'На складе',
            self::Reserved => 'В резерве',
            self::Assigned => 'Закреплено за сотрудником',
            self::Installed => 'Установлено в помещении',
            self::InTransit => 'В перемещении',
            self::Repair => 'В ремонте',
            self::TempIssued => 'Временно выдано',
            self::NotFound => 'Не найдено',
            self::Lost => 'Утеряно',
            self::Damaged => 'Повреждено',
            self::WrittenOff => 'Списано',
            self::Archived => 'Архив',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Assigned, self::Installed => 'green',
            self::InStock, self::Reserved, self::Created, self::Review => 'blue',
            self::InTransit, self::TempIssued, self::Repair => 'amber',
            self::NotFound, self::Lost, self::Damaged => 'red',
            self::WrittenOff, self::Archived => 'gray',
        };
    }

    /**
     * Обычные переходы, доступные IT-специалисту.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::Review, self::InStock, self::Reserved, self::Archived],
            self::Review => [self::InStock, self::Reserved, self::Damaged, self::WrittenOff],
            self::InStock => [self::Reserved, self::Assigned, self::Installed, self::InTransit, self::Repair,
                self::TempIssued, self::NotFound, self::Damaged, self::WrittenOff],
            self::Reserved => [self::InStock, self::Assigned, self::Installed, self::TempIssued],
            self::Assigned => [self::InStock, self::InTransit, self::Repair, self::NotFound, self::Lost, self::Damaged],
            self::Installed => [self::InStock, self::Assigned, self::InTransit, self::Repair, self::NotFound, self::Damaged],
            self::InTransit => [self::InStock, self::Installed, self::Assigned, self::Lost],
            self::Repair => [self::InStock, self::Damaged, self::WrittenOff],
            self::TempIssued => [self::InStock, self::Lost, self::Damaged],
            self::NotFound => [self::InStock, self::Assigned, self::Installed, self::Lost],
            self::Lost => [self::InStock, self::WrittenOff],
            self::Damaged => [self::Repair, self::InStock, self::WrittenOff],
            self::WrittenOff => [self::Archived],
            self::Archived => [],
        };
    }

    /**
     * Восстановление статуса администратором (списанный/архивный актив).
     *
     * @return list<self>
     */
    public function adminRestoreTransitions(): array
    {
        return match ($this) {
            self::WrittenOff, self::Archived, self::Lost => [self::Review, self::InStock],
            default => [],
        };
    }

    public function canTransitionTo(self $target, bool $isAdmin = false): bool
    {
        if ($target === $this) {
            return false;
        }
        if (in_array($target, $this->allowedTransitions(), true)) {
            return true;
        }

        return $isAdmin && in_array($target, $this->adminRestoreTransitions(), true);
    }

    /** Можно ли закрепить актив за сотрудником (выдача). */
    public function isIssuable(): bool
    {
        return in_array($this, [self::InStock, self::Reserved, self::Installed, self::NotFound, self::InTransit], true);
    }

    /** Актив выведен из оборота. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::WrittenOff, self::Archived], true);
    }

    /** @return array<string, string> label => value для форм */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }

        return $out;
    }

    /** Нормализация статуса из Excel (справочник соответствий по умолчанию). */
    public static function fromHuman(?string $value): ?self
    {
        $v = mb_strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        foreach (self::cases() as $c) {
            if ($v === $c->value || $v === mb_strtolower($c->label())) {
                return $c;
            }
        }

        return match (true) {
            str_contains($v, 'списан') => self::WrittenOff,
            str_contains($v, 'ремонт') => self::Repair,
            str_contains($v, 'склад') => self::InStock,
            str_contains($v, 'резерв') => self::Reserved,
            str_contains($v, 'утер') => self::Lost,
            str_contains($v, 'не найд') => self::NotFound,
            str_contains($v, 'поврежд'), str_contains($v, 'слома') => self::Damaged,
            str_contains($v, 'временн') => self::TempIssued,
            str_contains($v, 'выдан'), str_contains($v, 'закреп'), str_contains($v, 'использ'), $v === 'в работе' => self::Assigned,
            str_contains($v, 'установ') => self::Installed,
            str_contains($v, 'архив') => self::Archived,
            default => null,
        };
    }
}
