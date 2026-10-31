<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Services\StaffService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(StaffService::class)->update(auth()->user(), $record, $data);
        } catch (ValidationException $exception) {
            throw StaffResource::mapErrorsToForm($exception);
        }
    }
}
