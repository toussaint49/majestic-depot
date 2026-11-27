<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Filament\Resources\AuditLogs\Tables\AuditLogsTable;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')->label('Date')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('user.name')->label('Utilisateur')->placeholder('Système'),
                TextEntry::make('action')->label('Action')
                    ->formatStateUsing(fn (string $state) => AuditLogsTable::ACTIONS[$state] ?? $state),
                TextEntry::make('auditable_type')->label('Objet')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—'),
                TextEntry::make('auditable_id')->label('N°'),
                TextEntry::make('ip_address')->label('Adresse IP')->placeholder('—'),
                KeyValueEntry::make('old_values')->label('Avant')->columnSpanFull()
                    ->state(fn ($record) => self::stringify($record->old_values)),
                KeyValueEntry::make('new_values')->label('Après')->columnSpanFull()
                    ->state(fn ($record) => self::stringify($record->new_values)),
            ]);
    }

    private static function stringify(?array $values): array
    {
        return collect($values ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all();
    }
}
