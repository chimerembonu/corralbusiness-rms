<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Documents\PrintDocument;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer;

/** Turns a PrintDocument into raw ESC/POS bytes for a thermal printer. */
class EscPosRenderer
{
    /** Characters per line at normal size: 80 mm ≈ 48, 58 mm ≈ 32. */
    public static function columns(int $paperWidthMm): int
    {
        return $paperWidthMm <= 58 ? 32 : 48;
    }

    public function render(PrintDocument $document, int $paperWidthMm = 80): string
    {
        $connector = new DummyPrintConnector;
        $printer = new Printer($connector);
        $columns = self::columns($paperWidthMm);

        try {
            $printer->initialize();

            foreach ($document->lines() as $line) {
                match ($line['type']) {
                    'text' => $this->text($printer, $line, $columns),
                    'row' => $this->row($printer, $line, $columns),
                    'rule' => $this->rule($printer, $line['char'] ?? '-', $columns),
                    'feed' => $printer->feed((int) ($line['lines'] ?? 1)),
                    'cut' => $printer->cut(),
                    'drawer' => $printer->pulse(),
                    'image' => $this->image($printer, (string) $line['path'], $columns),
                    default => null,
                };
            }

            return $connector->getData();
        } finally {
            $printer->close();
        }
    }

    private function text(Printer $printer, array $line, int $columns): void
    {
        $this->style($printer, $line);
        $printer->setJustification(match ($line['align'] ?? 'left') {
            'center' => Printer::JUSTIFY_CENTER,
            'right' => Printer::JUSTIFY_RIGHT,
            default => Printer::JUSTIFY_LEFT,
        });
        $printer->text($this->ascii((string) $line['text'])."\n");
        $this->reset($printer);
    }

    private function row(Printer $printer, array $line, int $columns): void
    {
        $this->style($printer, $line);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $width = ($line['size'] ?? 'normal') === 'big' || ($line['size'] ?? 'normal') === 'wide' ? intdiv($columns, 2) : $columns;
        $left = $this->ascii((string) $line['left']);
        $right = $this->ascii((string) $line['right']);
        $space = $width - mb_strlen($right) - 1;
        $leftLines = $space > 0 ? mb_str_split($left, $space) : [$left];
        $last = array_pop($leftLines);
        foreach ($leftLines as $chunk) {
            $printer->text($chunk."\n");
        }
        $pad = max(1, $width - mb_strlen($last) - mb_strlen($right));
        $printer->text($last.str_repeat(' ', $pad).$right."\n");
        $this->reset($printer);
    }

    /** Prints a logo centred, scaled to roughly two thirds of the paper width. Missing files are skipped. */
    private function image(Printer $printer, string $path, int $columns): void
    {
        if (! is_file($path) || ! function_exists('imagecreatefromstring')) {
            return;
        }

        try {
            $maxWidth = $columns >= 48 ? 384 : 256;   // dots: 80 mm ≈ 576, 58 mm ≈ 384
            $img = EscposImage::load($path, false);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->bitImage($img->getWidth() > $maxWidth ? self::scaled($path, $maxWidth) : $img);
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_LEFT);
        } catch (\Throwable) {
            // A bad image must never stop a receipt.
        }
    }

    private static function scaled(string $path, int $maxWidth): EscposImage
    {
        $src = imagecreatefromstring((string) file_get_contents($path));
        $ratio = $maxWidth / imagesx($src);
        $dst = imagescale($src, $maxWidth, (int) round(imagesy($src) * $ratio));
        $tmp = tempnam(sys_get_temp_dir(), 'rms-logo-').'.png';
        imagepng($dst, $tmp);

        try {
            return EscposImage::load($tmp, false);
        } finally {
            @unlink($tmp);
        }
    }

    private function rule(Printer $printer, string $char, int $columns): void
    {
        $printer->text(str_repeat($char, $columns)."\n");
    }

    private function style(Printer $printer, array $line): void
    {
        $printer->setEmphasis((bool) ($line['bold'] ?? false));
        [$w, $h] = match ($line['size'] ?? 'normal') {
            'big' => [2, 2],
            'wide' => [2, 1],
            'tall' => [1, 2],
            default => [1, 1],
        };
        $printer->setTextSize($w, $h);
    }

    private function reset(Printer $printer): void
    {
        $printer->setEmphasis(false);
        $printer->setTextSize(1, 1);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
    }

    /** Most thermal printers only know single-byte code pages; swap the few symbols we use. */
    private function ascii(string $text): string
    {
        return strtr($text, ['₵' => 'C', '–' => '-', '—' => '-', '×' => 'x', '“' => '"', '”' => '"', '’' => "'", '·' => '.']);
    }
}
