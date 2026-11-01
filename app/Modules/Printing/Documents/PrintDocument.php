<?php

namespace App\Modules\Printing\Documents;

/**
 * A printable document described as simple lines, independent of the printer.
 * Renderers turn it into ESC/POS bytes or HTML.
 *
 *  $doc->center()->bold()->text('CORRAL DEMO CHOP BAR')->normal()
 *      ->row('1 x Jollof Rice', '60.00')->rule()->feed(2)->cut();
 */
final class PrintDocument
{
    /** @var array<int, array<string, mixed>> */
    private array $lines = [];

    private string $align = 'left';

    private bool $bold = false;

    private string $size = 'normal'; // normal | wide | tall | big

    public function __construct(public readonly string $title = '') {}

    public static function make(string $title = ''): self
    {
        return new self($title);
    }

    public function left(): self
    {
        $this->align = 'left';

        return $this;
    }

    public function center(): self
    {
        $this->align = 'center';

        return $this;
    }

    public function right(): self
    {
        $this->align = 'right';

        return $this;
    }

    public function bold(bool $on = true): self
    {
        $this->bold = $on;

        return $this;
    }

    public function normal(): self
    {
        $this->bold = false;
        $this->size = 'normal';
        $this->align = 'left';

        return $this;
    }

    /** Double height + width. */
    public function big(): self
    {
        $this->size = 'big';

        return $this;
    }

    public function tall(): self
    {
        $this->size = 'tall';

        return $this;
    }

    public function text(string $text): self
    {
        foreach (explode("\n", $text) as $line) {
            $this->lines[] = ['type' => 'text', 'text' => $line, 'align' => $this->align, 'bold' => $this->bold, 'size' => $this->size];
        }

        return $this;
    }

    /** Left + right columns on one line ("1 x Jollof ........ 60.00"). */
    public function row(string $left, string $right): self
    {
        $this->lines[] = ['type' => 'row', 'left' => $left, 'right' => $right, 'bold' => $this->bold, 'size' => $this->size];

        return $this;
    }

    public function rule(string $char = '-'): self
    {
        $this->lines[] = ['type' => 'rule', 'char' => $char];

        return $this;
    }

    public function feed(int $lines = 1): self
    {
        $this->lines[] = ['type' => 'feed', 'lines' => $lines];

        return $this;
    }

    public function cut(): self
    {
        $this->lines[] = ['type' => 'cut'];

        return $this;
    }

    /** Open the cash drawer connected to the printer. */
    /** A logo or picture file (PNG/JPG) printed centred; ignored by printers that cannot draw it. */
    public function image(string $path): self
    {
        $this->lines[] = ['type' => 'image', 'path' => $path];

        return $this;
    }

    public function drawer(): self
    {
        $this->lines[] = ['type' => 'drawer'];

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['title' => $this->title, 'lines' => $this->lines];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $doc = new self($data['title'] ?? '');
        $doc->lines = $data['lines'] ?? [];

        return $doc;
    }
}
