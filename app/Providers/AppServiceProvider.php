<?php

namespace App\Providers;

use App\Modules\Kitchen\Http\EnsureKitchenUser;
use App\Modules\Pos\Http\EnsurePosUser;
use App\Modules\Staff\Models\User;
use App\Support\Audit\SecurityLog;
use App\Support\Database\BlueprintMacros;
use App\Support\Licence\EnforceLicence;
use App\Support\Outbox\RecordToOutbox;
use App\Support\Outbox\ShouldRecordToOutbox;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        BlueprintMacros::register();

        // Every event implementing ShouldRecordToOutbox is appended to the outbox (CLAUDE.md §3.3).
        Event::listen(ShouldRecordToOutbox::class, RecordToOutbox::class);

        // The till and kitchen gates (active user, permission, till branch) run on every Livewire request too,
        // so deactivating someone mid-shift stops the screen they already have open (re-review).
        Livewire::addPersistentMiddleware([EnsurePosUser::class, EnsureKitchenUser::class, EnforceLicence::class]);

        // Wrong back-office / head-office passwords go to the security log (never the password itself).
        Event::listen(Failed::class, function (Failed $event) {
            SecurityLog::loginFailed($event->credentials['email'] ?? null, $event->user instanceof User ? $event->user : null, (string) Filament::getCurrentPanel()?->getId());
        });

        // Fail loudly in development if a model is missing a cast/relationship or is lazy-loaded.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
