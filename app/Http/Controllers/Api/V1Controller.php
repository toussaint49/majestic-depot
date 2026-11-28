<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Depot;
use App\Models\PackagingLevel;
use App\Models\PackagingType;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\CashSessionService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * API utilisée par Claude pour enregistrer les factures fournisseurs et les fiches de
 * vente. Toutes les règles métier passent par les mêmes services que l'application.
 */
class V1Controller extends Controller
{
    private function allow(string $permission): void
    {
        abort_unless(auth()->user()->can($permission), 403, "Permission « $permission » manquante.");
    }

    /** Tout ce qu'il faut pour faire correspondre une facture ou une fiche au catalogue. */
    public function catalogue(): JsonResponse
    {
        $this->allow('produits.view');

        return response()->json([
            'depots' => Depot::where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']),
            'products' => Product::with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('factor'), 'units.packagings'])
                ->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'reference' => $p->reference,
                    'units' => $p->units->map(fn ($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'factor' => $u->factor,
                        'price' => $u->price,
                        'empties' => $u->packagings->map(fn ($r) => ['packaging_type_id' => $r->packaging_type_id, 'quantity' => $r->quantity]),
                    ]),
                ]),
            'packaging_types' => PackagingType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'payment_accounts' => PaymentAccount::where('is_active', true)->orderBy('id')->get(['id', 'name', 'kind']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']),
        ]);
    }

    /** Stock de boissons et de vides, en unité de base. */
    public function stock(Request $request): JsonResponse
    {
        $this->allow('stocks.view');
        $depotId = $request->integer('depot_id') ?: Depot::orderBy('id')->value('id');

        return response()->json([
            'depot_id' => $depotId,
            'products' => StockLevel::where('depot_id', $depotId)->with('product:id,name')->get()
                ->map(fn ($l) => ['product_id' => $l->product_id, 'name' => $l->product?->name, 'quantity' => $l->quantity, 'avg_cost' => (float) $l->avg_cost]),
            'packaging' => PackagingLevel::where('depot_id', $depotId)->with('packagingType:id,name')->get()
                ->map(fn ($l) => ['packaging_type_id' => $l->packaging_type_id, 'name' => $l->packagingType?->name, 'quantity' => $l->quantity]),
        ]);
    }

    /**
     * Bilan d'une journée pour les contrôles croisés de la fiche de vente :
     * stock au début et à la fin, entrées, ventes, encaissements par compte.
     */
    public function day(Request $request): JsonResponse
    {
        $this->allow('ventes.view');
        $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'depot_id' => ['nullable', 'integer']]);
        $depotId = $request->integer('depot_id') ?: Depot::orderBy('id')->value('id');
        $from = Carbon::parse($request->string('date'))->startOfDay();
        $to = $from->copy()->endOfDay();

        $movements = StockMovement::where('depot_id', $depotId)->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('product_id, type, sum(quantity) as qty')->groupBy('product_id', 'type')->get();
        $before = StockMovement::where('depot_id', $depotId)->where('occurred_at', '<', $from)
            ->selectRaw('product_id, sum(quantity) as qty')->groupBy('product_id')->pluck('qty', 'product_id');

        $products = Product::whereIn('id', $movements->pluck('product_id')->merge($before->keys())->unique())->pluck('name', 'id');
        $perProduct = $products->map(function ($name, $id) use ($movements, $before) {
            $own = $movements->where('product_id', $id);
            $opening = (int) ($before[$id] ?? 0);
            $net = (int) $own->sum('qty');

            return [
                'product_id' => $id,
                'name' => $name,
                'opening' => $opening,
                'movements' => $own->mapWithKeys(fn ($m) => [$m->type->value => (int) $m->qty])->all(),
                'closing' => $opening + $net,
            ];
        })->values();

        $sales = Sale::where('depot_id', $depotId)->where('status', Sale::VALIDATED)->whereBetween('occurred_at', [$from, $to]);
        $payments = Payment::where('depot_id', $depotId)->where('status', Payment::VALID)->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('payment_account_id, sum(amount) as total')->groupBy('payment_account_id')->pluck('total', 'payment_account_id');

        return response()->json([
            'date' => $from->toDateString(),
            'depot_id' => $depotId,
            'products' => $perProduct,
            'sales' => [
                'count' => (clone $sales)->count(),
                'total' => (int) (clone $sales)->sum('total'),
                'paid_at_sale' => (int) (clone $sales)->sum('paid_at_sale'),
                'credit' => (int) (clone $sales)->sum('credit_amount'),
                'numbers' => (clone $sales)->orderBy('id')->pluck('number'),
            ],
            'payments_by_account' => $payments->map(fn ($v) => (int) $v),
            'purchases' => Purchase::where('depot_id', $depotId)->where('status', Purchase::VALIDATED)->whereBetween('occurred_at', [$from, $to])
                ->get(['number', 'invoice_number', 'total']),
        ]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $this->allow('fournisseurs.create');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255']]);
        $existing = Supplier::where('name', $data['name'])->first();

        return response()->json($existing ?? Supplier::create($data), $existing ? 200 : 201);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $this->allow('clients.create');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255']]);
        $existing = Customer::where('name', $data['name'])->first();

        return response()->json($existing ?? Customer::create($data), $existing ? 200 : 201);
    }

    public function storePurchase(Request $request, PurchaseService $purchases): JsonResponse
    {
        $this->allow('achats.create');
        $data = $request->validate([
            'depot_id' => ['nullable', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'invoice_number' => ['nullable', 'string', 'max:60'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'empties_returned' => ['nullable', 'array'],
            'empties_returned.*.packaging_type_id' => ['required', 'integer'],
            'empties_returned.*.quantity' => ['required', 'integer', 'min:1'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_account_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
        ]);
        $data['depot_id'] ??= Depot::orderBy('id')->value('id');
        $data['notes'] = trim(($data['notes'] ?? '').' [saisi par Claude]');

        $purchase = $purchases->create($data);

        return response()->json($purchase->load('lines'), 201);
    }

    public function storeSale(Request $request, SaleService $sales): JsonResponse
    {
        $this->allow('ventes.create');
        $data = $request->validate([
            'depot_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'empties_returned' => ['required', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_account_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
        ]);
        $data['depot_id'] ??= Depot::orderBy('id')->value('id');
        $data['notes'] = trim(($data['notes'] ?? '').' [saisi par Claude]');

        return response()->json($sales->create($data)->load('lines'), 201);
    }

    public function cash(Request $request, CashSessionService $cash): JsonResponse
    {
        $this->allow('caisses.view');
        $depot = Depot::findOrFail($request->integer('depot_id') ?: Depot::orderBy('id')->value('id'));
        $session = $cash->current($depot) ?? CashSession::where('depot_id', $depot->id)->latest('id')->first();

        return response()->json([
            'session' => $session,
            'expected' => $session?->isOpen() ? $cash->expected($session) : null,
            'suggested_float' => $cash->suggestedFloat(),
        ]);
    }

    public function openCash(Request $request, CashSessionService $cash): JsonResponse
    {
        $this->allow('caisses.open');
        $data = $request->validate(['depot_id' => ['nullable', 'integer'], 'opening_float' => ['required', 'integer', 'min:0'], 'reason' => ['nullable', 'string', 'max:255']]);
        $depot = Depot::findOrFail($data['depot_id'] ?? Depot::orderBy('id')->value('id'));

        return response()->json($cash->open($depot, $data['opening_float'], $data['reason'] ?? null), 201);
    }

    /** Clôture avec les montants relevés sur la fiche. La validation reste faite par le propriétaire. */
    public function closeCash(Request $request, CashSessionService $cash): JsonResponse
    {
        $this->allow('caisses.close');
        $data = $request->validate([
            'depot_id' => ['nullable', 'integer'],
            'counted' => ['required', 'array'],
            'counted.*' => ['integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $depot = Depot::findOrFail($data['depot_id'] ?? Depot::orderBy('id')->value('id'));
        $session = $cash->current($depot);
        abort_unless($session, 422, 'Aucune caisse ouverte.');

        return response()->json($cash->close($session, $data['counted'], $data['notes'] ?? null)->load('counts'));
    }
}
