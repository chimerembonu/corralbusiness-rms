<?php

namespace App\Console\Commands;

use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Services\DailySummaryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\StoreTenantResolver;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan rms:load-test` — simulates a lunch service (6 tills taking orders, kitchen bumping)
 * and reports how long each step takes and how many database queries it needs.
 * Everything is rolled back at the end unless --keep is given.
 */
class LoadTestCommand extends Command
{
    protected $signature = 'rms:load-test {--tills=6} {--orders=60 : Orders in total across all tills} {--items=3 : Lines per order} {--keep : Keep the orders (default: roll back)} {--json : Print the summary as JSON}';

    protected $description = 'Simulate a lunch service on the demo restaurant and report timings and query counts';

    /** @var array<string, array<int, array{ms: float, queries: int}>> */
    private array $samples = [];

    /** @var array<string, array{count: int, ms: float, example: string}> */
    private array $sql = [];

    private int $queryCount = 0;

    public function handle(StoreTenantResolver $store): int
    {
        $tenant = $store->tenant();
        if (! $tenant) {
            $this->components->error('No restaurant found. Run php artisan rms:demo first.');

            return self::FAILURE;
        }
        Tenancy::set($tenant, $store->branch($tenant));

        DB::listen(function ($query) {
            $this->queryCount++;
            $key = preg_replace('/\s+/', ' ', preg_replace('/\d+/', '?', substr($query->sql, 0, 120)));
            $this->sql[$key] ??= ['count' => 0, 'ms' => 0.0, 'example' => $query->sql];
            $this->sql[$key]['count']++;
            $this->sql[$key]['ms'] += $query->time;
        });

        $run = function () {
            $this->simulate((int) $this->option('tills'), (int) $this->option('orders'), (int) $this->option('items'));
        };

        if ($this->option('keep')) {
            $run();
        } else {
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
        }

        $this->report();

        return self::SUCCESS;
    }

    private function simulate(int $tills, int $orders, int $lines): void
    {
        // Only this restaurant's staff: the store database may hold more than one restaurant.
        $staff = fn () => User::query()->where('tenant_id', Tenancy::tenantId())->active();
        $cashiers = $staff()->permission(Permission::PaymentsTake->value)->orderBy('name')->get();
        $cashiers = $cashiers->isEmpty() ? $staff()->get() : $cashiers;
        $tillRows = Till::query()->active()->orderBy('code')->limit($tills)->get();
        $tables = DiningTable::query()->get();
        $cash = app(CashService::class);
        $orderService = app(OrderService::class);
        $payments = app(PaymentService::class);
        $kitchen = app(KitchenService::class);
        $menu = app(MenuService::class);
        $today = $orderService->businessDate();

        $sessions = [];
        foreach ($tillRows as $i => $till) {
            $cashier = $cashiers[$i % $cashiers->count()];
            $sessions[] = ['till' => $till, 'user' => $cashier, 'session' => $cash->current($cashier) ?? $cash->open($till, $cashier, Money::fromDecimal('500'), $today)];
        }
        if ($sessions === []) {
            $owner = $cashiers->first();
            $sessions[] = ['till' => null, 'user' => $owner, 'session' => $cash->open(null, $owner, Money::fromDecimal('500'), $today)];
        }

        $catalog = $this->measure('menu: load catalog', fn () => $menu->catalogFor(Tenancy::branchId()));
        $items = $catalog->flatMap(fn ($c) => $c->items)->filter(fn (Item $i) => ! $i->isCombo())->values();
        if ($items->isEmpty()) {
            $this->components->error('The menu is empty.');

            return;
        }

        $this->components->info(sprintf('Simulating %d orders across %d till(s), %d lines each, %d menu items…', $orders, count($sessions), $lines, $items->count()));
        $bar = $this->output->createProgressBar($orders);

        for ($n = 0; $n < $orders; $n++) {
            $slot = $sessions[$n % count($sessions)];
            $user = $slot['user'];
            $table = $n % 3 === 0 ? $tables->first(fn (DiningTable $t) => $t->status()->value === 'free') : null;

            $order = $this->measure('order: open', fn () => $table
                ? $orderService->open(OrderType::DineIn, $user, $table, 2)
                : $orderService->open(OrderType::Takeaway, $user));

            for ($l = 0; $l < $lines; $l++) {
                $item = $items[($n * 7 + $l * 3) % $items->count()];
                $variant = $item->variants->first();
                $modifiers = [];
                foreach ($item->modifierGroups as $group) {
                    if ($group->min_select > 0 && $group->modifiers->isNotEmpty()) {
                        $modifiers[$group->modifiers->first()->id] = 1;
                    }
                }
                $this->measure('order: add item', fn () => $orderService->addItem($order, $item, $variant, 1 + ($l % 2), $modifiers, null, $user));
            }

            $this->measure('order: send to kitchen', fn () => $orderService->sendToKitchen($order->fresh(), $user));
            $this->measure('order: load screen (HTTP /pos/orders/{id})', fn () => $this->http($user, '/pos/orders/'.$order->id));

            if ($n % 2 === 0) {
                $tickets = $this->measure('kitchen: active tickets', fn () => $kitchen->activeTickets());
                if ($ticket = $tickets->first()) {
                    $this->measure('kitchen: bump ticket', fn () => $kitchen->done($ticket, $user));
                }
            }

            $this->measure('order: pay cash', fn () => $payments->payCash($order->fresh(), Money::fromDecimal('500'), $user));
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $owner = User::query()->where('tenant_id', Tenancy::tenantId())->active()->permission(Permission::ReportsView->value)->first() ?? $sessions[0]['user'];
        foreach ([1, 2, 3] as $i) {
            $this->measure('screen: /pos (HTTP)', fn () => $this->http($sessions[0]['user'], '/pos'));
            $this->measure('screen: /kitchen (HTTP)', fn () => $this->http($owner, '/kitchen'));
            $this->measure('screen: /pos/today (HTTP)', fn () => $this->http($owner, '/pos/today'));
            $this->measure('screen: /pos/cash (HTTP)', fn () => $this->http($sessions[0]['user'], '/pos/cash'));
            $this->measure('report: today summary', fn () => app(DailySummaryService::class)->forDate($today));
        }
    }

    private function http(User $user, string $uri): int
    {
        Auth::guard('web')->setUser($user);
        $request = Request::create($uri, 'GET');
        $response = app(Kernel::class)->handle($request);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $this->components->warn("{$uri} answered {$status}");
        }

        return $status;
    }

    private function measure(string $name, callable $fn): mixed
    {
        $queriesBefore = $this->queryCount;
        $start = hrtime(true);
        $result = $fn();
        $this->samples[$name][] = ['ms' => (hrtime(true) - $start) / 1e6, 'queries' => $this->queryCount - $queriesBefore];

        return $result;
    }

    private function report(): void
    {
        $rows = [];
        $summary = [];
        foreach ($this->samples as $name => $samples) {
            $ms = array_column($samples, 'ms');
            sort($ms);
            $p95 = $ms[(int) floor(0.95 * (count($ms) - 1))];
            $queries = array_column($samples, 'queries');
            $summary[$name] = ['count' => count($ms), 'avg_ms' => round(array_sum($ms) / count($ms), 1), 'p95_ms' => round($p95, 1), 'max_ms' => round(max($ms), 1), 'avg_queries' => round(array_sum($queries) / count($queries), 1), 'max_queries' => max($queries)];
            $rows[] = [$name, count($ms), $summary[$name]['avg_ms'], $summary[$name]['p95_ms'], $summary[$name]['max_ms'], $summary[$name]['avg_queries'], max($queries)];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['operations' => $summary, 'queries_total' => $this->queryCount], JSON_PRETTY_PRINT));

            return;
        }

        $this->table(['Operation', 'Runs', 'Avg ms', 'p95 ms', 'Max ms', 'Avg queries', 'Max queries'], $rows);

        uasort($this->sql, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $this->components->info('Heaviest SQL (total time):');
        $this->table(['Total ms', 'Count', 'Query'], array_map(fn ($s) => [round($s['ms'], 1), $s['count'], substr($s['example'], 0, 110)], array_slice($this->sql, 0, 12)));
        $this->components->info("Queries in total: {$this->queryCount}");
        $this->components->info('Targets (CLAUDE.md): add item < 200 ms, POS screen < 1 s, KDS < 2 s.');
    }
}
