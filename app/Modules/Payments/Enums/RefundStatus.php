<?php

namespace App\Modules\Payments\Enums;

/** Where a refund through a payment provider stands (Complete Edition E2.4). */
enum RefundStatus: string
{
    case Queued = 'queued';             // waiting to be sent to the provider (or the provider could not be reached yet)
    case Sent = 'sent';                 // the provider accepted it and is processing
    case Refunded = 'refunded';         // the provider says the money went back
    case Failed = 'failed';             // the provider refused or gave up: refund by hand
    case ByHand = 'by_hand';            // this provider cannot send money back: refund by hand
    case DoneByHand = 'done_by_hand';   // a manager confirmed the money was given back by hand

    public function label(): string
    {
        return __('payments::payments.refunds.status.'.$this->value);
    }

    /** A person has to give the money back. */
    public function needsHand(): bool
    {
        return in_array($this, [self::Failed, self::ByHand], true);
    }

    /** Nothing more for the app to do. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Refunded, self::DoneByHand], true);
    }
}
