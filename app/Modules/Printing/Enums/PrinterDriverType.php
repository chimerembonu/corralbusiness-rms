<?php

namespace App\Modules\Printing\Enums;

enum PrinterDriverType: string
{
    case Network = 'network';  // ESC/POS over TCP, usually port 9100
    case Usb = 'usb';          // ESC/POS via a CUPS raw queue (Phase 1.5)
    case Browser = 'browser';  // the till's browser prints an HTML page

    public function label(): string
    {
        return __('printing::printing.driver.'.$this->value);
    }
}
