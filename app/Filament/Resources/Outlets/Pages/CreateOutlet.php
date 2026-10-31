<?php

namespace App\Filament\Resources\Outlets\Pages;

use App\Filament\Resources\Outlets\OutletResource;
use App\Models\Business;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOutlet extends CreateRecord
{
    protected static string $resource = OutletResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // Resolve the business only among those this account may manage, so a
        // foreign business_id can never be used, even if form validation is bypassed.
        $business = Business::query()
            ->whereIn('id', OutletResource::accessibleBusinessIds())
            ->findOrFail($data['business_id']);

        return $business->outlets()->create(['name' => $data['name']]);
    }
}
