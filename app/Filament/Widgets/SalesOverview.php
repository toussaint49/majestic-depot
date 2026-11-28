<?php

namespace App\Filament\Widgets;

use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\Quantity;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Tableau de bord : chiffres du jour et crédits clients. */
class SalesOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()->can('ventes.view');
    }

    protected function getStats(): array
    {
        $today = Sale::where('status', Sale::VALIDATED)->whereDate('occurred_at', today());
        $customers = Customer::query()->withBalances()->get();

        $stats = [
            Stat::make('Ventes du jour', Quantity::money((int) (clone $today)->sum('total')))
                ->description((clone $today)->count().' vente(s)'),
            Stat::make('Crédits en cours', Quantity::money((int) $customers->sum('balance_due')))
                ->description($customers->where('balance_due', '>', 0)->count().' client(s)'),
            Stat::make('Crédits en retard', Quantity::money((int) $customers->sum('overdue_due')))
                ->description($customers->where('overdue_due', '>', 0)->count().' client(s) au-delà de l\'échéance')
                ->color($customers->sum('overdue_due') > 0 ? 'danger' : 'success'),
        ];

        if (auth()->user()->can('tresorerie.view')) {
            array_splice($stats, 1, 0, [Stat::make('Encaissé aujourd\'hui', Quantity::money((int) AccountTransaction::whereDate('occurred_at', today())->sum('amount')))
                ->description('Espèces + Mobile Money, net des annulations')]);
        }

        return $stats;
    }
}
