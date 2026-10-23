<x-filament-panels::page>
    @if ($outletOptions === [])
        <x-filament::section>
            You do not own an outlet yet.
        </x-filament::section>
    @else
        <div style="max-width: 28rem;">
            <label for="stock-outlet" style="display: block; margin-bottom: 0.5rem; font-size: 0.875rem; font-weight: 500;">
                Outlet
            </label>

            <x-filament::input.wrapper>
                <x-filament::input.select id="stock-outlet" wire:model.live="outletId">
                    <option value="">Select an outlet</option>

                    @foreach ($outletOptions as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        @if ($hasSelectedOutlet)
            {{ $this->table }}
        @else
            <x-filament::section>
                Select an outlet to see its stock.
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
