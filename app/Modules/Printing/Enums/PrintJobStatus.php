<?php

namespace App\Modules\Printing\Enums;

enum PrintJobStatus: string
{
    case Queued = 'queued';
    case Printing = 'printing';
    case Printed = 'printed';
    case Failed = 'failed';
    case WaitingBrowser = 'waiting_browser';

    public function label(): string
    {
        return __('printing::printing.status.'.$this->value);
    }
}
