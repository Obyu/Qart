<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // Resolve the business through the user's own businesses so a foreign
        // business_id can never be used, even if form validation is bypassed.
        $business = auth()->user()->businesses()->findOrFail($data['business_id']);

        return $business->categories()->create(['name' => $data['name']]);
    }
}
