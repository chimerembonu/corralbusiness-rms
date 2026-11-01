<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Reports\Filament\Pages\ItemsReport;
use App\Modules\Reports\Filament\Pages\SalesReport;
use App\Modules\Reports\Filament\Pages\TaxReport;
use App\Modules\Reports\Filament\Pages\VoidsReport;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->owner = User::factory()->forBranch($this->branch)->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->actingAs($this->owner);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->owner);
    app(OrderService::class)->addItem($order, $this->menu['coke'], quantity: 2); // 24.00
    app(PaymentService::class)->payCash($order, Money::fromDecimal('24'), $this->owner);
});

it('shows the report pages and the dashboard figures', function () {
    $this->get('/admin')->assertOk()->assertSee('Gross sales')->assertSee('GH₵ 24.00')->assertSee('Sales, last 14 days');
    $this->get('/admin/reports/sales')->assertOk()->assertSee('Sales report')->assertSee('GH₵ 24.00')->assertSee('Takeaway')->assertSee('Cash')->assertSee('Chimere');
    $this->get('/admin/reports/items')->assertOk()->assertSee('Coca-Cola')->assertSee('Drinks')->assertSee('100%');
    $this->get('/admin/reports/voids')->assertOk()->assertSee('Cancelled orders');
    $this->get('/admin/reports/tax')->assertOk()->assertSee('VAT')->assertSee('15%');

    Livewire::test(SalesReport::class)->call('preset', 'last_month')->assertSee('No sales in this period');
    Livewire::test(SalesReport::class, ['from' => now()->subDays(3)->toDateString(), 'to' => now()->toDateString()])->assertSee('GH₵ 24.00');

    $accountant = User::factory()->forBranch($this->branch)->create();
    $accountant->assignRole(Role::Accountant->value);
    $this->flushSession();
    $this->actingAs($accountant)->get('/admin/reports/tax')->assertOk();
    $waiter = User::factory()->forBranch($this->branch)->create();
    $waiter->assignRole(Role::Waiter->value);
    $this->flushSession();
    $this->actingAs($waiter)->get('/admin/reports/sales')->assertForbidden();
});

it('exports every report to Excel and PDF', function () {
    Excel::fake();
    $today = now()->toDateString();

    Livewire::test(SalesReport::class)->callAction('excel')->assertHasNoErrors();
    Excel::assertDownloaded("sales-report-{$today}-to-{$today}.xlsx", function ($export) {
        $sheets = $export->sheets();

        return count($sheets) === 6 && $sheets[1]->array()[0][2] === '24.00' && $sheets[1]->title() === 'Sales by day';
    });
    Livewire::test(ItemsReport::class)->callAction('excel')->assertHasNoErrors();
    Excel::assertDownloaded("items-categories-{$today}-to-{$today}.xlsx");
    Livewire::test(VoidsReport::class)->callAction('excel')->assertHasNoErrors();
    Livewire::test(TaxReport::class)->callAction('excel')->assertHasNoErrors();
    Excel::assertDownloaded("tax-report-{$today}-to-{$today}.xlsx", fn ($export) => $export->sheets()[0]->array()[0][3] === '4.00');

    foreach ([SalesReport::class => 'sales-report', ItemsReport::class => 'items-categories', VoidsReport::class => 'voids-discounts-refunds', TaxReport::class => 'tax-report'] as $page => $slug) {
        Livewire::test($page)->callAction('pdf')->assertHasNoErrors()->assertFileDownloaded("{$slug}-{$today}-to-{$today}.pdf");
    }
});
