<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Contracts\PrinterDriver;
use App\Modules\Printing\Drivers\BrowserPrint;
use App\Modules\Printing\Drivers\NetworkEscPos;
use App\Modules\Printing\Drivers\UsbEscPos;
use App\Modules\Printing\Enums\PrinterDriverType;

class PrinterDriverRegistry
{
    /** @var array<string, class-string<PrinterDriver>> */
    private array $drivers = [
        'network' => NetworkEscPos::class,
        'usb' => UsbEscPos::class,
        'browser' => BrowserPrint::class,
    ];

    public function for(PrinterDriverType $type): PrinterDriver
    {
        return app($this->drivers[$type->value]);
    }

    /** Swap a driver (used by tests to fake the network). */
    public function register(PrinterDriverType $type, string $class): void
    {
        $this->drivers[$type->value] = $class;
    }
}
