<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Resources\Categories\CategoryResource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        $businessIds = CategoryResource::accessibleBusinessIds();

        return $schema
            ->components([
                Select::make('business_id')
                    ->label('Business')
                    ->relationship(
                        name: 'business',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->whereIn('id', $businessIds),
                    )
                    ->rules([
                        Rule::exists('businesses', 'id')->whereIn('id', $businessIds),
                    ])
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
