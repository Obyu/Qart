<?php

namespace App\Filament\Resources\Staff\Schemas;

use App\Enums\StaffRole;
use App\Models\Business;
use App\Models\Outlet;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(table: 'users', column: 'email', ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->maxLength(255)
                    ->helperText(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Leave blank to keep the current password.'
                        : null),
                Select::make('business_id')
                    ->label('Business')
                    ->options(fn (): array => Business::query()
                        ->where('user_id', auth()->id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->rules([
                        Rule::exists('businesses', 'id')->where('user_id', auth()->id()),
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('outlet_id', null))
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                Select::make('role')
                    ->options(self::roleOptions())
                    ->rules([Rule::enum(StaffRole::class)])
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state === StaffRole::BusinessAdmin->value) {
                            $set('outlet_id', null);
                        }
                    })
                    ->helperText('Business Admin works across the whole business. Cashier and Kitchen are tied to one outlet.'),
                Select::make('outlet_id')
                    ->label('Outlet')
                    ->options(function (Get $get): array {
                        $businessId = $get('business_id');

                        if (blank($businessId)) {
                            return [];
                        }

                        return Outlet::query()
                            ->where('business_id', $businessId)
                            ->whereHas('business', fn ($query) => $query->where('user_id', auth()->id()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $belongs = Outlet::query()
                                ->whereKey($value)
                                ->where('business_id', $get('business_id'))
                                ->exists();

                            if (! $belongs) {
                                $fail('The selected outlet does not belong to the selected business.');
                            }
                        },
                    ])
                    ->visible(fn (Get $get): bool => self::needsOutlet($get('role')))
                    ->required(fn (Get $get): bool => self::needsOutlet($get('role'))),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        $options = [];

        foreach (StaffRole::cases() as $role) {
            $options[$role->value] = Str::headline($role->value);
        }

        return $options;
    }

    public static function needsOutlet(mixed $role): bool
    {
        return in_array($role, [StaffRole::Cashier->value, StaffRole::Kitchen->value], true);
    }
}
