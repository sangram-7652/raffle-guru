<?php

namespace App\Filament\Resources\Admins\RelationManagers;

use App\Filament\Support\AdminGate;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Audit history for one admin account, read-only. Gated by
 * AdminAuditLogPolicy (audit.view), so the section is absent for anyone
 * without it, and the rows are only ever loaded for an authorized viewer.
 * Shows curated fields and the actor's name; the raw metadata array is
 * never rendered.
 */
class AuditRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogsAsTarget';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Audit history';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return AdminGate::allows('viewAny', AdminAuditLog::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event')
                    ->label('Event')
                    ->formatStateUsing(fn (string $state): string => Str::headline(str_replace('admin.', '', $state))),
                TextColumn::make('change')
                    ->label('Change')
                    ->state(fn (AdminAuditLog $log): ?string => self::change($log))
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (AdminAuditLog $log): ?string => $log->metadata['reason'] ?? null)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('actor.name')
                    ->label('By')
                    ->placeholder('Unknown admin'),
                TextColumn::make('created_at')
                    ->label('At')
                    ->dateTime(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['actor:id,name'])
                ->orderByDesc('id'))
            ->defaultSort(null);
    }

    private static function change(AdminAuditLog $log): ?string
    {
        $metadata = $log->metadata ?? [];

        if (isset($metadata['from'], $metadata['to'])) {
            return Str::headline($metadata['from']).' → '.Str::headline($metadata['to']);
        }

        if (isset($metadata['changed'])) {
            return 'Changed: '.implode(', ', (array) $metadata['changed']);
        }

        return null;
    }
}
