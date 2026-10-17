<?php

namespace App\Filament\Resources\ProductVariants\Schemas;

use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class ProductVariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->relationship(
                        name: 'product',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query
                            ->with(['category', 'business'])
                            ->whereHas('business', fn (Builder $business) => $business->where('user_id', auth()->id())),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Product $record): string => "{$record->name} — {$record->category->name} ({$record->business->name})"
                    )
                    ->searchable(['name'])
                    ->rules([
                        Rule::exists('products', 'id')->where(
                            fn ($query) => $query->whereIn(
                                'business_id',
                                fn ($businesses) => $businesses
                                    ->select('id')
                                    ->from('businesses')
                                    ->where('user_id', auth()->id())
                            )
                        ),
                    ])
                    ->required()
                    ->hidden(fn (string $operation): bool => $operation === 'edit'),
                TextInput::make('name')
                    ->label('Variant name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                TextInput::make('price')
                    ->label('Price')
                    ->required()
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->maxValue(999999999999.99)
                    // Authoritative rule: plain decimal text, at most 12 integer digits and
                    // 2 decimals. It rejects signs, exponents, separators and a 3rd decimal,
                    // and keeps the value a string so no float is involved.
                    ->rules(['regex:/^\d{1,12}(\.\d{1,2})?$/']),
            ]);
    }
}
