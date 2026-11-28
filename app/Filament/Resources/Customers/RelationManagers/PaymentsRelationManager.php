<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Paiements';

    public function table(Table $table): Table
    {
        return PaymentResource::table($table)->heading('Paiements')->filters([]);
    }
}
