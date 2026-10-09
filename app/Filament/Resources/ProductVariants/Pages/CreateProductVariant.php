<?php

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductVariant extends CreateRecord
{
    protected static string $resource = ProductVariantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // Resolve the product through the user's own products so a foreign
        // product_id can never be used, even if form validation is bypassed.
        $product = ProductResource::getEloquentQuery()->findOrFail($data['product_id']);

        return $product->variants()->create([
            'name' => $data['name'],
            'sku' => $data['sku'],
        ]);
    }
}
