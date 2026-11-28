@php($money = fn ($v) => number_format((int) $v, 0, ',', ' '))
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 8pt; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #000; }
    .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
    h1 { font-size: 11pt; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1pt 0; vertical-align: top; }
    .sep { border-top: 1px dashed #000; margin: 4pt 0; }
    .big { font-size: 10pt; }
</style>
</head>
<body>
    <div class="c">
        <h1>{{ $tenant->name }}</h1>
        @if($sale->depot)<div>{{ $sale->depot->name }}</div>@endif
        @if($tenant->address)<div>{{ $tenant->address }}</div>@endif
        @if($tenant->phone)<div>Tél. {{ $tenant->phone }}</div>@endif
        @if($tenant->tax_number)<div>NIU {{ $tenant->tax_number }}</div>@endif
    </div>
    <div class="sep"></div>
    <table>
        <tr><td>Ticket</td><td class="r b">{{ $sale->number }}</td></tr>
        <tr><td>Date</td><td class="r">{{ $sale->occurred_at->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Client</td><td class="r">{{ $sale->customer?->name ?? 'Comptoir' }}</td></tr>
        <tr><td>Vendeur</td><td class="r">{{ $sale->user?->name ?? '—' }}</td></tr>
    </table>
    @if($sale->isCancelled())
        <div class="sep"></div><div class="c b big">*** VENTE ANNULÉE ***</div>
    @endif
    <div class="sep"></div>
    <table>
        @foreach($sale->lines as $line)
            <tr><td colspan="2">{{ $line->product_name }}</td></tr>
            <tr><td>&nbsp;&nbsp;{{ $line->quantity }} {{ $line->unit_name }} × {{ $money($line->unit_price) }}</td><td class="r">{{ $money($line->line_total) }}</td></tr>
        @endforeach
    </table>
    <div class="sep"></div>
    <table>
        <tr class="big b"><td>TOTAL</td><td class="r">{{ $money($sale->total) }} FCFA</td></tr>
        @foreach($sale->payments as $payment)
            <tr><td>{{ $payment->account->name }}@if(! $payment->isValid()) (annulé)@endif</td><td class="r">{{ $money($payment->amount) }}</td></tr>
        @endforeach
        @if($sale->credit_amount > 0)
            <tr class="b"><td>Reste à payer</td><td class="r">{{ $money($sale->credit_amount) }}</td></tr>
            <tr><td>Échéance</td><td class="r">{{ $sale->due_date?->format('d/m/Y') }}</td></tr>
        @endif
    </table>
    @if(count($empties))
        <div class="sep"></div>
        <div class="b">Vides échangés</div>
        <table>
            @foreach($empties as $name => $qty)
                <tr><td>{{ $name }}</td><td class="r">{{ $qty }}</td></tr>
            @endforeach
        </table>
    @endif
    <div class="sep"></div>
    <div class="c">Merci de votre visite !</div>
</body>
</html>
