<?php

use App\Modules\Reports\Exports\TableExport;
use App\Support\Excel\SafeValueBinder;
use Maatwebsite\Excel\Excel as Writer;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * Security review L1: a staff member or item named "=HYPERLINK(...)" used to become a live formula in
 * every Excel export. Formula-looking text is now stored as plain text; numbers stay numbers.
 */
it('stores formula-looking text as plain text in exports, and keeps numbers as numbers', function () {
    $rows = [
        ['=HYPERLINK("http://evil.example","Click")', '12.50'],
        ['+1+1', '-5'],
        ['-2+3', '0'],
        ['@SUM(A1)', '3'],
        ["\t=cmd|' /C calc'!A0", '1'],
        ['Kofi Mensah', '7'],
    ];
    $bytes = Excel::raw(new TableExport('Staff', ['Name', 'Hours'], $rows), Writer::XLSX);
    $path = sys_get_temp_dir().'/rms-l1-'.uniqid().'.xlsx';
    file_put_contents($path, $bytes);

    $sheet = IOFactory::load($path)->getActiveSheet();
    @unlink($path);

    foreach ([2, 3, 4, 5, 6] as $row) {
        $cell = $sheet->getCell("A{$row}");
        expect($cell->getDataType())->toBe(DataType::TYPE_STRING, "row {$row} must be text, not a formula")
            ->and($cell->getValue())->toStartWith("'");
    }
    expect($sheet->getCell('A2')->getValue())->toBe("'=HYPERLINK(\"http://evil.example\",\"Click\")")
        ->and($sheet->getCell('A7')->getValue())->toBe('Kofi Mensah')
        ->and($sheet->getCell('B2')->getValue())->toBe(12.5)
        ->and($sheet->getCell('B3')->getValue())->toBe(-5)
        ->and($sheet->getCell('B3')->getDataType())->toBe(DataType::TYPE_NUMERIC);
});

it('recognises every spreadsheet trigger character and nothing else', function () {
    foreach (['=1', '+1a', '-x', '@x', "\tx", "\rx"] as $hostile) {
        expect(SafeValueBinder::looksLikeFormula($hostile))->toBeTrue($hostile);
    }
    foreach (['Jollof', '12.50', '-5', '+3', '', ' =1', 'a=b'] as $plain) {
        expect(SafeValueBinder::looksLikeFormula($plain))->toBeFalse($plain);
    }
});
