<?php

namespace App\Providers;

use App\Modules\Kitchen\Http\EnsureKitchenUser;
use App\Modules\Pos\Http\EnsurePosUser;
use App\Support\Database\BlueprintMacros;
use App\Support\Outbox\RecordToOutbox;
use App\Support\Outbox\ShouldRecordToOutbox;
use App\Support\Tenancy\TenantContext;
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
        Livewire::addPersistentMiddleware([EnsurePosUser::class, EnsureKitchenUser::class]);

        // Fail loudly in development if a model is missing a cast/relationship or is lazy-loaded.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
