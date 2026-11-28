<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class SalesRelationManager extends RelationManager
{
    protected static string $relationship = 'sales';

    protected static ?string $title = 'Ventes';

    public function table(Table $table): Table
    {
        return SaleResource::table($table)->heading('Ventes')->filters([]);
    }
}
