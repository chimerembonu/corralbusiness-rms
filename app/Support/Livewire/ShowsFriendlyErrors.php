<?php

namespace App\Support\Livewire;

use App\Support\Errors\FriendlyError;
use Illuminate\Validation\ValidationException;

/**
 * Till, kitchen and customer screens: when a button press fails with an error nobody caught, show it in
 * the screen's own red message line instead of Livewire's white error box. The first load of a screen is
 * left alone, so a refused page still shows the full "no access" page with a way back.
 * Livewire's own errors (tampered requests) are left alone too.
 *
 * The component must have a public ?string $error that its view displays.
 */
trait ShowsFriendlyErrors
{
    protected bool $friendlyErrorsReady = false;

    public function hydrateShowsFriendlyErrors(): void
    {
        $this->friendlyErrorsReady = true;
    }

    public function exceptionShowsFriendlyErrors($e, $stopPropagation): void
    {
        if (! $this->friendlyErrorsReady || $e instanceof ValidationException || str_starts_with($e::class, 'Livewire\\')) {
            return;
        }

        $this->error = FriendlyError::message($e);
        $stopPropagation();
    }
}
