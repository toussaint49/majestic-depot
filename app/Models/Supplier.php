<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Supplier extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'phone', 'address', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** Reste à payer au fournisseur. */
    public function balance(): int
    {
        $bought = (int) $this->purchases()->where('status', Purchase::VALIDATED)->sum('total');
        $paid = (int) $this->payments()->where('status', SupplierPayment::VALID)
            ->whereHas('purchase', fn ($q) => $q->where('status', Purchase::VALIDATED))->sum('amount');

        return $bought - $paid;
    }

    /** Ajoute `balance_due` calculé en SQL. Même résultat que balance(). */
    public function scopeWithBalance(Builder $query): Builder
    {
        $bought = DB::table('purchases')->selectRaw('coalesce(sum(total), 0)')
            ->whereColumn('purchases.supplier_id', 'suppliers.id')->where('purchases.status', Purchase::VALIDATED);
        $paid = DB::table('supplier_payments')->selectRaw('coalesce(sum(supplier_payments.amount), 0)')
            ->join('purchases', 'purchases.id', '=', 'supplier_payments.purchase_id')
            ->whereColumn('supplier_payments.supplier_id', 'suppliers.id')
            ->where('supplier_payments.status', SupplierPayment::VALID)->where('purchases.status', Purchase::VALIDATED);

        return $query->select('suppliers.*')
            ->selectRaw('('.$bought->toSql().') - ('.$paid->toSql().') as balance_due', array_merge($bought->getBindings(), $paid->getBindings()));
    }
}
