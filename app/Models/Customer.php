<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'phone', 'address', 'notes', 'credit_days', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'credit_days' => 'integer'];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditDays(): int
    {
        return $this->credit_days ?? (int) (app(CurrentTenant::class)->get()?->default_credit_days ?? 14);
    }

    /**
     * Ventes validées qui ont encore un reste dû, de la plus ancienne à la plus récente.
     *
     * @return Collection<int, Sale>
     */
    public function openSales(): Collection
    {
        return Sale::query()->where('customer_id', $this->id)->where('status', Sale::VALIDATED)
            ->withBalance()->orderBy('occurred_at')->orderBy('id')->get()
            ->filter(fn (Sale $s) => $s->balance() > 0)->values();
    }

    /** Total dû par le client. */
    public function balance(): int
    {
        return (int) $this->openSales()->sum(fn (Sale $s) => $s->balance());
    }

    public function overdueBalance(): int
    {
        return (int) $this->openSales()->filter(fn (Sale $s) => $s->isOverdue())->sum(fn (Sale $s) => $s->balance());
    }

    /**
     * Ajoute `balance_due` et `overdue_due` calculés en SQL (listes, tris, filtres).
     * Même résultat que balance() / overdueBalance().
     */
    public function scopeWithBalances(Builder $query): Builder
    {
        $sales = fn (bool $overdue) => DB::table('sales')->selectRaw('coalesce(sum(total), 0)')
            ->whereColumn('sales.customer_id', 'customers.id')->where('sales.status', Sale::VALIDATED)
            ->when($overdue, fn ($q) => $q->whereDate('sales.due_date', '<', today()));
        $paid = fn (bool $overdue) => DB::table('payment_allocations')->selectRaw('coalesce(sum(payment_allocations.amount), 0)')
            ->join('sales', 'sales.id', '=', 'payment_allocations.sale_id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereColumn('sales.customer_id', 'customers.id')->where('sales.status', Sale::VALIDATED)->where('payments.status', Payment::VALID)
            ->when($overdue, fn ($q) => $q->whereDate('sales.due_date', '<', today()));

        $sql = fn (bool $overdue) => '('.$sales($overdue)->toSql().') - ('.$paid($overdue)->toSql().')';
        $bindings = fn (bool $overdue) => array_merge($sales($overdue)->getBindings(), $paid($overdue)->getBindings());

        return $query->select('customers.*')
            ->selectRaw($sql(false).' as balance_due', $bindings(false))
            ->selectRaw($sql(true).' as overdue_due', $bindings(true));
    }
}
