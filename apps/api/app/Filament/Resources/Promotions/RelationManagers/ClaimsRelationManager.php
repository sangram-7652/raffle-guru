<?php

namespace App\Filament\Resources\Promotions\RelationManagers;

use App\Enums\PromotionClaimStatus;
use App\Models\PromotionClaim;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only — a claim's lifecycle is written exclusively by
 * PromotionClaimService (see PromotionClaim's immutability guard once
 * Fulfilled); no admin action here ever mutates one. Shows only the
 * same customer fields (`user.name`/`user.email`) already surfaced
 * elsewhere in admin (Customers/Orders resources) — no new PII
 * exposure.
 */
class ClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'claims';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Claims';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', PromotionClaim::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Customer'),
                TextColumn::make('user.email')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PromotionClaimStatus $state): string => match ($state) {
                        PromotionClaimStatus::Fulfilled => 'success',
                        PromotionClaimStatus::Failed => 'danger',
                        PromotionClaimStatus::Pending => 'warning',
                    }),
                TextColumn::make('failure_reason')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('entry_id')
                    ->label('Entry')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,email'))
            ->defaultSort('created_at', 'desc');
    }
}
