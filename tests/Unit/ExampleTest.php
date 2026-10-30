<?php

it('uses the Africa/Accra timezone and GHS currency', function () {
    expect(config('app.timezone'))->toBe('Africa/Accra')
        ->and(config('rms.currency'))->toBe('GHS');
});
