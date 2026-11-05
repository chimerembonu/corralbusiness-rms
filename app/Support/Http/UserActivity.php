<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * Was this request made by a person, or by a screen refreshing itself? Idle timers (E9.2) count only
 * the first: a page load, a form, a button. Livewire's own background refreshes (wire:poll with no
 * method, i.e. "$refresh", and nothing typed) are not activity.
 */
final class UserActivity
{
    public static function isPerson(Request $request): bool
    {
        if (! $request->hasHeader('X-Livewire')) {
            return true;
        }
        foreach ((array) $request->input('components', []) as $component) {
            if (! empty($component['updates'])) {
                return true;
            }
            foreach ((array) ($component['calls'] ?? []) as $call) {
                if (($call['method'] ?? '') !== '$refresh') {
                    return true;
                }
            }
        }

        return false;
    }
}
