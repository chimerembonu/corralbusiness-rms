<?php

namespace App\Modules\Printing\Documents;

use App\Modules\Printing\Models\Printer;

final class TestPageDocument
{
    public static function make(Printer $printer): PrintDocument
    {
        return PrintDocument::make(__('printing::printing.test_title'))
            ->center()->bold()->big()->text(__('rms.product_name'))->normal()
            ->center()->text(__('printing::printing.test_title'))->normal()
            ->rule()
            ->row(__('printing::printing.fields.name'), $printer->name)
            ->row(__('printing::printing.fields.driver'), $printer->driver->label())
            ->row(__('printing::printing.fields.address'), (string) $printer->address)
            ->row(__('printing::printing.fields.paper_width'), $printer->paper_width.' mm')
            ->row(__('pos::pos.receipt.date'), now()->format('d/m/Y H:i:s'))
            ->rule()
            ->text('ABCDEFGHIJKLMNOPQRSTUVWXYZ 0123456789')
            ->text('GH₵ 1,234.56  Akwaaba!  Medaase!')
            ->rule()
            ->center()->text(__('printing::printing.test_ok'))->normal()
            ->feed(2)->cut();
    }
}
