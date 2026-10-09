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
            ]);
    }
}
