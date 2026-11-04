<?php

use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\StockTakeService;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\CashierSession;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Pos\Services\TrainingMode;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;

/*
 * Role access guard: signs in as each of the 8 roles and opens every page in the app, then compares
 * who got in against the approved list in tests/Fixtures/role-access.json.
 *
 * A failure means a page was opened to (or closed to) a role that the approved list does not allow,
 * or a new page was added without deciding who may open it. To approve a deliberate change, re-run
 * with ROLE_ACCESS_WRITE=1 and commit the updated JSON after reviewing its diff.
 *
 * "In" = the page opened (200), or sent the user on to open a till first (pos/orders/{id}/pay → pos/cash).
 * Anything else (403, 404, sent to the login, rider or kitchen screen) = kept out.
 */
it('lets each role open exactly the pages it should', function () {
    $this->artisan('rms:demo', ['--password' => 'demo-pass-123'])->assertSuccessful();
    $tenant = Tenant::firstOrFail();
    $branch = $tenant->branches()->first();
    Tenancy::set($tenant, $branch);

    $users = [];
    foreach (Role::cases() as $role) {
        $u = User::factory()->forBranch($branch)->withPin((string) (7000 + count($users)))->create(['name' => 'Probe '.$role->value]);
        $u->syncRoles([$role->value]);
        $users[$role->value] = $u;
    }
    $owner = $users['owner'];
    $till = Till::query()->first();

    // Fixtures for pages that take a record.
    $orders = app(OrderService::class);
    $cash = app(CashService::class);
    app(CurrentTill::class)->remember($till);
    $session = $cash->current($owner, $branch->id) ?? $cash->open($till, $owner, Money::of(50000), $orders->businessDate());
    $menuSvc = app(MenuService::class);
    $item = Item::query()->whereDoesntHave('variants')->whereDoesntHave('modifierGroups')->get()
        ->first(fn ($i) => $menuSvc->isOrderable($i, null, $branch->id));
    $paid = $orders->open(OrderType::Takeaway, $owner);
    $orders->addItem($paid, $item, null, 1, [], null, $owner);
    $orders->sendToKitchen($paid, $owner);
    app(PaymentService::class)->payCash($paid->fresh(), Money::of(1000000), $owner);
    $open = $orders->open(OrderType::Takeaway, $owner);
    $orders->addItem($open, $item, null, 1, [], null, $owner);
    $customer = Customer::query()->first() ?? Customer::create(['name' => 'Probe Customer', 'phone' => '0200000099']);
    $stockTake = app(StockTakeService::class)->start($owner);
    $job = PrintJob::query()->first();

    $params = [
        'order' => $open->id, 'session' => CashierSession::query()->first()?->id, 'job' => $job?->id,
        'customer' => $customer->id, 'stockTake' => $stockTake->id,
    ];
    $receiptOrder = $paid->id;

    // Filament {record} → first record of that resource's model.
    $recordFor = [];
    foreach (['admin', 'hq'] as $panel) {
        foreach (Filament::getPanel($panel)->getResources() as $resource) {
            $slug = $resource::getSlug();
            $recordFor[$panel.'/'.$slug] = $resource::getModel()::query()->first()?->getRouteKey();
        }
    }

    $uris = [];
    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods())) {
            continue;
        }
        $uri = $route->uri();
        if (preg_match('#^(livewire|_|filament/(exports|imports)|storage|up$|broadcasting|admin/login|hq/login|pos/login|clock-in$|clock$|/$)#', $uri) || $uri === '/') {
            continue;
        }
        $url = $uri;
        if (str_contains($uri, '{record}')) {
            $key = preg_replace('#/\{record\}.*$#', '', $uri);
            $rec = $recordFor[$key] ?? null;
            if (! $rec) {
                $uris[$uri] = null;

                continue;
            }
            $url = str_replace('{record}', $rec, $uri);
        }
        foreach ($params as $name => $value) {
            $url = str_replace('{'.$name.'}', (string) $value, $url);
        }
        if (str_contains($uri, 'pos/orders/{order}/receipt')) {
            $url = 'pos/orders/'.$receiptOrder.'/receipt';
        }
        $uris[$uri] = str_contains($url, '{') ? null : '/'.ltrim($url, '/');
    }
    ksort($uris);

    $laravelRedirect = app('redirect');
    $matrix = [];
    foreach ($users as $roleName => $user) {
        foreach ($uris as $uri => $url) {
            if ($url === null) {
                $matrix[$uri][$roleName] = 'NOFIXTURE';

                continue;
            }
            $this->flushSession();
            Tenancy::forget();
            app()->forgetInstance(TrainingMode::class);
            app()->forgetInstance(CurrentTill::class);
            auth()->forgetGuards();
            // Each request must start clean: a page refused in mount() never puts Laravel's redirector back.
            app()->instance('redirect', $laravelRedirect);
            Facade::clearResolvedInstance('redirect');
            Livewire\Livewire::flushState();
            $this->actingAs($user);
            app(CurrentTill::class)->remember($till);
            try {
                $res = $this->get($url);
                $code = $res->getStatusCode();
                $cell = (string) $code;
                if ($code >= 300 && $code < 400) {
                    $to = parse_url($res->headers->get('Location'), PHP_URL_PATH) ?: '?';
                    $cell = '302>'.$to;
                }
                if ($code >= 500) {
                    $cell = '500 '.substr(strip_tags((string) ($res->exception?->getMessage() ?? '')), 0, 120);
                }
            } catch (Throwable $e) {
                $cell = 'EXC '.class_basename($e).': '.substr($e->getMessage(), 0, 120);
            }
            $matrix[$uri][$roleName] = $cell;
        }
    }

    $errors = [];
    $actual = [];
    foreach ($matrix as $uri => $byRole) {
        foreach ($byRole as $roleName => $cell) {
            if (str_starts_with($cell, '500') || str_starts_with($cell, 'EXC')) {
                $errors[] = "{$roleName} {$uri}: {$cell}";
            }
            if ($cell === 'NOFIXTURE') {
                continue;
            }
            $in = $cell === '200' || ($cell === '302>/pos/cash' && str_ends_with($uri, '/pay'));
            $actual[$uri] ??= [];
            if ($in) {
                $actual[$uri][] = $roleName;
            }
        }
    }

    $fixture = base_path('tests/Fixtures/role-access.json');
    if (getenv('ROLE_ACCESS_WRITE')) {
        @mkdir(dirname($fixture), 0755, true);
        file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
    $approved = json_decode((string) @file_get_contents($fixture), true) ?? [];

    $problems = $errors;
    foreach ($actual as $uri => $roles) {
        if (! array_key_exists($uri, $approved)) {
            $problems[] = "NEW PAGE {$uri} opens for [".implode(', ', $roles).'] - decide who may open it and approve';

            continue;
        }
        foreach (array_diff($roles, $approved[$uri]) as $r) {
            $problems[] = "TOO OPEN  {$uri}: {$r} got in but is not approved";
        }
        foreach (array_diff($approved[$uri], $roles) as $r) {
            $problems[] = "TOO SHUT  {$uri}: {$r} is approved but was kept out";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});
