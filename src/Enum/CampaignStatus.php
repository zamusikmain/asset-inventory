<?php

namespace App\Enum;

/** Статусы кампании инвентаризации (п. 12.1 ТЗ). */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Preparation = 'preparation';
    case Started = 'started';
    case InProgress = 'in_progress';
    case AwaitingReview = 'awaiting_review';
    case WithCommission = 'with_commission';
    case Approved = 'approved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Preparation => 'Подготовка',
            self::Started => 'Начата',
            self::InProgress => 'Проводится',
            self::AwaitingReview => 'Ожидает проверки',
            self::WithCommission => 'Передана комиссии',
            self::Approved => 'Утверждена',
            self::Closed => 'Закрыта',
            self::Cancelled => 'Отменена',
        };
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Preparation, self::Cancelled],
            self::Preparation => [self::Started, self::Draft, self::Cancelled],
            self::Started => [self::InProgress, self::Cancelled],
            self::InProgress => [self::AwaitingReview, self::Cancelled],
            self::AwaitingReview => [self::WithCommission, self::InProgress],
            self::WithCommission => [self::Approved, self::AwaitingReview],
            self::Approved => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $t): bool
    {
        return in_array($t, $this->next(), true);
    }

    /** В этих статусах принимаются сканы. */
    public function acceptsScans(): bool
    {
        return in_array($this, [self::Started, self::InProgress], true);
    }
}
