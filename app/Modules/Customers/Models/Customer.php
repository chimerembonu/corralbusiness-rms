<?php

namespace App\Modules\Customers\Models;

use App\Modules\Orders\Models\Order;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer of the restaurant (shared by every branch). */
class Customer extends BusinessModel
{
    use Auditable;

    protected bool $hasBranch = false;

    protected $fillable = ['name', 'phone', 'email', 'tin', 'notes', 'tags', 'allow_account', 'credit_limit', 'is_active'];

    protected $attributes = ['allow_account' => false, 'account_balance' => 0, 'points_balance' => 0, 'orders_count' => 0, 'total_spent' => 0, 'is_active' => true];

    protected array $auditExcept = ['orders_count', 'total_spent', 'last_order_at', 'points_balance', 'account_balance', 'phone_digits'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'allow_account' => 'boolean',
            'credit_limit' => MoneyCast::class,
            'account_balance' => 'integer',
            'points_balance' => 'integer',
            'orders_count' => 'integer',
            'total_spent' => MoneyCast::class,
            'last_order_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $customer->phone_digits = self::digits($customer->phone);
        });
    }

    /** Digits only: "+233 24 111 2222" → "233241112222"; a local "024…" becomes "23324…". */
    public static function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '233'.substr($digits, 1);
        }

        return $digits;
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderBy('label');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest('opened_at');
    }

    public function accountTransactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class)->latest('happened_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Name or phone contains the term; a numeric term also matches the digits. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $digits = preg_replace('/\D+/', '', $term);
        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0'); // "024…" is stored as "23324…"
        }

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', "%{$term}%");
            if ($digits !== '') {
                $q->orWhere('phone_digits', 'like', "%{$digits}%");
            }
        });
    }

    public function balance(): Money
    {
        return Money::of($this->account_balance);
    }

    public function owes(): bool
    {
        return $this->account_balance > 0;
    }

    /** How much more may go on the account (null = no limit). */
    public function creditAvailable(): ?Money
    {
        return $this->credit_limit ? Money::of(max(0, $this->credit_limit->amount - $this->account_balance)) : null;
    }

    public function defaultAddress(): ?CustomerAddress
    {
        $addresses = $this->relationLoaded('addresses') ? $this->addresses : $this->addresses()->get();

        return $addresses->firstWhere('is_default', true) ?? $addresses->first();
    }
}
