<?php

namespace App\Filament\Resources\ProductVariants\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductVariantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Variant name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                // The cast already yields a two-decimal string such as "12.50".
                // No currency symbol: the currency has not been decided yet.
                TextColumn::make('price')
                    ->label('Price')
                    ->placeholder('Not set')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Product'),
                TextColumn::make('product.category.name')
                    ->label('Category'),
                TextColumn::make('product.business.name')
                    ->label('Business'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
