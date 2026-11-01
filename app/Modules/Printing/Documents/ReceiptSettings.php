<?php

namespace App\Modules\Printing\Documents;

use Illuminate\Support\Facades\Storage;

/** What the owner set under Back office → Receipt design. */
final class ReceiptSettings
{
    public function __construct(
        public readonly ?string $header = null,
        public readonly ?string $footer = null,
        public readonly ?string $taxNumber = null,
        public readonly ?string $logoPath = null,   // on the "public" disk
        public readonly bool $showServer = true,
    ) {}

    /** @return array<int, string> header split into lines */
    public function headerLines(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $this->header))));
    }

    public function logoFile(): ?string
    {
        if (! $this->logoPath) {
            return null;
        }
        $disk = Storage::disk('public');
        $path = ltrim($this->logoPath, '/');

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    public function logoUrl(): ?string
    {
        return $this->logoPath ? Storage::disk('public')->url(ltrim($this->logoPath, '/')) : null;
    }
}
