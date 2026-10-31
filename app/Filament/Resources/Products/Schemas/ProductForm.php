<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $businessIds = ProductResource::accessibleBusinessIds();

        return $schema
            ->components([
                Select::make('category_id')
                    ->label('Category')
                    ->relationship(
                        name: 'category',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query
                            ->with('business')
                            ->whereIn('business_id', $businessIds),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Category $record): string => "{$record->name} ({$record->business->name})"
                    )
                    ->rules([
                        Rule::exists('categories', 'id')->whereIn('business_id', $businessIds),
                    ])
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
