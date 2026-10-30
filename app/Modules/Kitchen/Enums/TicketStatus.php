<?php

namespace App\Modules\Kitchen\Enums;

enum TicketStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return __('kitchen::kitchen.status.'.$this->value);
    }

    public function next(): self
    {
        return match ($this) {
            self::New => self::InProgress,
            self::InProgress, self::Done => self::Done,
        };
    }
}
