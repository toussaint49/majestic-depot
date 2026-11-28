@php($money = fn ($v) => number_format((int) $v, 0, ',', ' '))
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
    h1 { font-size: 15pt; margin: 0 0 4pt; } h2 { font-size: 11pt; margin: 14pt 0 4pt; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 4pt; border-bottom: 1px solid #ddd; text-align: left; }
    th { background: #f2f2f2; }
    .r { text-align: right; } .b { font-weight: bold; } .muted { color: #666; } .red { color: #b00; }
</style>
</head>
<body>
    <table style="border: none">
        <tr>
            <td style="border: none">
                <h1>{{ $tenant->name }}</h1>
                <div class="muted">{{ $tenant->address }} @if($tenant->phone) · Tél. {{ $tenant->phone }} @endif</div>
            </td>
            <td style="border: none" class="r">
                <div class="b">Relevé de compte client</div>
                <div>{{ $customer->name }}</div>
                <div class="muted">{{ $customer->phone }}</div>
                <div class="muted">Du {{ $from->format('d/m/Y') }} au {{ $until->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <h2>Mouvements</h2>
    <table>
        <thead><tr><th>Date</th><th>Libellé</th><th class="r">Achats</th><th class="r">Paiements</th><th class="r">Solde dû</th></tr></thead>
        <tbody>
            <tr><td></td><td class="muted">Solde au {{ $from->format('d/m/Y') }}</td><td></td><td></td><td class="r">{{ $money($opening) }}</td></tr>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['date']->format('d/m/Y') }}</td>
                    <td>{{ $row['label'] }}</td>
                    <td class="r">{{ $row['debit'] ? $money($row['debit']) : '' }}</td>
                    <td class="r">{{ $row['credit'] ? $money($row['credit']) : '' }}</td>
                    <td class="r">{{ $money($row['balance']) }}</td>
                </tr>
            @endforeach
            <tr class="b"><td></td><td>Solde au {{ $until->format('d/m/Y') }}</td><td></td><td></td><td class="r">{{ $money($closing) }} FCFA</td></tr>
        </tbody>
    </table>

    <h2>Ventes restant à payer</h2>
    @if($open->isEmpty())
        <p class="muted">Aucune.</p>
    @else
        <table>
            <thead><tr><th>Vente</th><th>Date</th><th>Échéance</th><th class="r">Total</th><th class="r">Reste dû</th></tr></thead>
            <tbody>
                @foreach($open as $sale)
                    <tr class="{{ $sale->isOverdue() ? 'red' : '' }}">
                        <td>{{ $sale->number }}</td>
                        <td>{{ $sale->occurred_at->format('d/m/Y') }}</td>
                        <td>{{ $sale->due_date?->format('d/m/Y') }}@if($sale->isOverdue()) (en retard)@endif</td>
                        <td class="r">{{ $money($sale->total) }}</td>
                        <td class="r b">{{ $money($sale->balance()) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <p class="muted" style="margin-top: 20pt">Édité le {{ now()->format('d/m/Y H:i') }}</p>
</body>
</html>
