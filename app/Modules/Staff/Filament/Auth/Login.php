<?php

namespace App\Modules\Staff\Filament\Auth;

use App\Support\Audit\SecurityLog;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/** Filament's sign-in page, plus a security-log entry when too many wrong passwords block it (compliance assessment). */
class Login extends FilamentLogin
{
    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        SecurityLog::loginThrottled($this->data['email'] ?? null, (string) Filament::getCurrentPanel()?->getId());

        return parent::getRateLimitedNotification($exception);
    }
}
