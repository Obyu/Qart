<?php

namespace App\Filament\Resources\Products\Schemas;

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
        return $schema
            ->components([
                Select::make('category_id')
                    ->label('Category')
                    ->relationship(
                        name: 'category',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query
                            ->with('business')
                            ->whereHas('business', fn (Builder $business) => $business->where('user_id', auth()->id())),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Category $record): string => "{$record->name} ({$record->business->name})"
                    )
                    ->rules([
                        Rule::exists('categories', 'id')->where(
                            fn ($query) => $query->whereIn(
                                'business_id',
                                fn ($businesses) => $businesses
                                    ->select('id')
                                    ->from('businesses')
                                    ->where('user_id', auth()->id())
                            )
                        ),
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
