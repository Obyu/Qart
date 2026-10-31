<?php

namespace App\Filament\Resources\Outlets\Schemas;

use App\Filament\Resources\Outlets\OutletResource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class OutletForm
{
    public static function configure(Schema $schema): Schema
    {
        $businessIds = OutletResource::accessibleBusinessIds();

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
