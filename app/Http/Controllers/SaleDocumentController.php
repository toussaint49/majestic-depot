<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PackagingType;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\PackagingService;
use App\Support\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/** Ticket de caisse (80 mm) et relevé client, en PDF. */
class SaleDocumentController extends Controller
{
    public function ticket(Request $request, string $tenant, int $sale): Response
    {
        abort_unless($request->user()->can('ventes.view'), 403);
        $sale = Sale::with(['lines', 'customer', 'user', 'depot', 'payments.account'])->findOrFail($sale);

        $empties = $sale->lines->isEmpty() ? [] : app(PackagingService::class)->required($sale->lines->map(fn ($l) => [$l->product_unit_id, $l->quantity])->all());
        $emptyNames = PackagingType::whereIn('id', array_keys($empties))->pluck('name', 'id');

        $height = 330 + 26 * $sale->lines->count() + 14 * $sale->payments->count() + 14 * count($empties);
        $pdf = Pdf::loadView('pdf.ticket', [
            'tenant' => app(CurrentTenant::class)->get(),
            'sale' => $sale,
            'empties' => collect($empties)->mapWithKeys(fn ($q, $id) => [$emptyNames[$id] => $q]),
        ])->setPaper([0, 0, 226.77, $height]);

        return $pdf->stream("ticket-{$sale->number}.pdf");
    }

    public function statement(Request $request, string $tenant, int $customer): Response
    {
        abort_unless($request->user()->can('clients.view'), 403);
        $customer = Customer::findOrFail($customer);

        $from = $request->date('from') ?? now()->subMonths(3)->startOfDay();
        $until = ($request->date('until') ?? now())->endOfDay();

        $sales = Sale::where('customer_id', $customer->id)->where('status', Sale::VALIDATED)
            ->whereBetween('occurred_at', [$from, $until])->get();
        $payments = Payment::where('customer_id', $customer->id)->where('status', Payment::VALID)
            ->whereBetween('occurred_at', [$from, $until])->get();

        $opening = $this->balanceAt($customer, $from);
        $rows = collect()
            ->merge($sales->map(fn (Sale $s) => ['date' => $s->occurred_at, 'label' => "Vente {$s->number}", 'debit' => $s->total, 'credit' => 0]))
            ->merge($payments->map(fn (Payment $p) => ['date' => $p->occurred_at, 'label' => "Paiement {$p->number}", 'debit' => 0, 'credit' => $p->amount]))
            ->sortBy('date')->values();

        $running = $opening;
        $rows = $rows->map(function ($row) use (&$running) {
            $running += $row['debit'] - $row['credit'];

            return $row + ['balance' => $running];
        });

        return Pdf::loadView('pdf.statement', [
            'tenant' => app(CurrentTenant::class)->get(),
            'customer' => $customer,
            'from' => $from,
            'until' => $until,
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $running,
            'open' => $customer->openSales(),
        ])->setPaper('a4')->stream("releve-{$customer->id}.pdf");
    }

    /** Solde dû par le client juste avant une date (ventes − paiements valides). */
    private function balanceAt(Customer $customer, Carbon $date): int
    {
        $sold = (int) Sale::where('customer_id', $customer->id)->where('status', Sale::VALIDATED)->where('occurred_at', '<', $date)->sum('total');
        $paid = (int) Payment::where('customer_id', $customer->id)->where('status', Payment::VALID)->where('occurred_at', '<', $date)->sum('amount');

        return $sold - $paid;
    }
}
