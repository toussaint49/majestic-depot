<?php

namespace App\Filament\Resources\ApiTokens;

use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Models\ApiToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Accès API (Claude)';

    protected static ?string $modelLabel = 'jeton d\'accès';

    protected static ?string $pluralModelLabel = 'jetons d\'accès';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nom'),
                TextColumn::make('hint')->label('Se termine par')->formatStateUsing(fn ($state) => '…'.$state),
                TextColumn::make('user.name')->label('Agit au nom de'),
                TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y H:i'),
                TextColumn::make('last_used_at')->label('Dernière utilisation')->dateTime('d/m/Y H:i')->placeholder('Jamais'),
                TextColumn::make('status')->label('Statut')->badge()
                    ->state(fn (ApiToken $r) => $r->isActive() ? 'Actif' : 'Révoqué')
                    ->color(fn (ApiToken $r) => $r->isActive() ? 'success' : 'danger'),
            ])
            ->recordActions([
                Action::make('revoke')->label('Révoquer')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (ApiToken $record) => $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription('Ce jeton cessera immédiatement de fonctionner.')
                    ->action(function (ApiToken $record) {
                        $record->forceFill(['revoked_at' => now()])->save();
                        Notification::make()->success()->title('Jeton révoqué')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListApiTokens::route('/')];
    }
}
