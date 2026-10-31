<?php

namespace App\Filament\Resources\Staff\Tables;

use App\Filament\Resources\Staff\Schemas\StaffForm;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        'business_admin' => 'warning',
                        'cashier' => 'success',
                        'kitchen' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('business.name')
                    ->label('Business'),
                TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->placeholder('All outlets'),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(StaffForm::roleOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
