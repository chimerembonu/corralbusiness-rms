<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Events\OrderEvent;
use App\Modules\Orders\Services\TableService;

/** When an order is paid or cancelled, tables joined onto its table become free again. */
class ReleaseMergedTables
{
    public function __construct(private readonly TableService $tables) {}

    public function handle(OrderEvent $event): void
    {
        $this->tables->releaseMergedTables($event->order);
    }
}
