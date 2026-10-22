<x-filament-panels::page>
    <style>
        .cashier-stack { display: flex; flex-direction: column; gap: 1rem; }
        .cashier-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); align-items: end; }
        .cashier-field label { display: block; margin-bottom: .25rem; font-size: .875rem; font-weight: 500; }
        .cashier-banner { padding: .75rem 1rem; border: 1px solid; border-radius: .5rem; font-size: .95rem; }
        .cashier-banner--success { background: rgba(34, 197, 94, .15); border-color: rgba(34, 197, 94, .6); }
        .cashier-banner--error { background: rgba(239, 68, 68, .15); border-color: rgba(239, 68, 68, .6); }
        .cashier-hint { font-size: .9rem; opacity: .75; }
        .cashier-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .75rem; }
        .cashier-badge { display: inline-block; padding: .125rem .625rem; border: 1px solid; border-radius: 9999px; font-size: .8rem; font-weight: 600; }
        .cashier-badge--open { background: rgba(245, 158, 11, .15); border-color: rgba(245, 158, 11, .6); }
        .cashier-badge--completed { background: rgba(34, 197, 94, .15); border-color: rgba(34, 197, 94, .6); }
        .cashier-scroll { overflow-x: auto; }
        .cashier-table { width: 100%; border-collapse: collapse; font-size: .95rem; }
        .cashier-table th, .cashier-table td { padding: .5rem .75rem; text-align: left; border-bottom: 1px solid rgba(128, 128, 128, .3); }
        .cashier-table .cashier-num { text-align: right; white-space: nowrap; }
        .cashier-total { display: flex; justify-content: space-between; gap: 1rem; padding: .75rem; font-size: 1.25rem; font-weight: 700; }
        .cashier-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1rem; }
    </style>

    @php
        $ready = $outletId !== null
            && $outletId !== ''
            && array_key_exists((int) $outletId, $outletOptions);
    @endphp

    <div class="cashier-stack">
        @if ($feedback)
            <div
                class="cashier-banner cashier-banner--{{ $feedback['type'] === 'error' ? 'error' : 'success' }}"
                role="{{ $feedback['type'] === 'error' ? 'alert' : 'status' }}"
            >
                {{ $feedback['message'] }}
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">Sale setup</x-slot>

            @if ($businessOptions === [])
                <p class="cashier-hint">You do not own a business yet. Create a business and an outlet first.</p>
            @else
                <div class="cashier-grid">
                    <div class="cashier-field">
                        <label for="cashier-business">Business</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="cashier-business" wire:model.live="businessId">
                                <option value="">Select a business</option>
                                @foreach ($businessOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    <div class="cashier-field">
                        <label for="cashier-outlet">Outlet</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="cashier-outlet" wire:model.live="outletId">
                                <option value="">Select an outlet</option>
                                @foreach ($outletOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                </div>

                @if ($outletOptions === [] && $businessId)
                    <p class="cashier-hint" style="margin-top: .75rem;">This business has no outlets yet.</p>
                @endif
            @endif
        </x-filament::section>

        @if ($ready && ($order === null || $order['isOpen']))
            <x-filament::section>
                <x-slot name="heading">Add item</x-slot>

                @if ($variantOptions === [])
                    <p class="cashier-hint">This business has no product variants yet.</p>
                @else
                    <div class="cashier-grid">
                        <div class="cashier-field">
                            <label for="cashier-variant">Product variant</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="cashier-variant" wire:model="variantId">
                                    <option value="">Select a product variant</option>
                                    @foreach ($variantOptions as $variant)
                                        <option value="{{ $variant['id'] }}">{{ $variant['label'] }} · {{ $variant['price'] }}@if ($variant['stock'] !== null) · stock {{ $variant['stock'] }}@endif</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        <div class="cashier-field">
                            <label for="cashier-quantity">Quantity</label>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    id="cashier-quantity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    inputmode="numeric"
                                    wire:model="quantity"
                                    wire:keydown.enter="addItem"
                                />
                            </x-filament::input.wrapper>
                        </div>

                        <div>
                            <x-filament::button wire:click="addItem" wire:loading.attr="disabled" wire:target="addItem">
                                Add to sale
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if ($ready)
            <x-filament::section>
                <x-slot name="heading">Current sale</x-slot>

                @if ($order === null)
                    <p class="cashier-hint">No sale in progress. Add a product variant to start one.</p>
                @else
                    <div class="cashier-head">
                        <strong>Order #{{ $order['id'] }}</strong>
                        @if ($order['isOpen'])
                            <span class="cashier-badge cashier-badge--open">Open</span>
                        @else
                            <span class="cashier-badge cashier-badge--completed">Completed {{ $order['completedAt'] }}</span>
                        @endif
                    </div>

                    @if (! $order['hasItems'])
                        <p class="cashier-hint">No items yet. Add a product variant above.</p>
                    @else
                        <div class="cashier-scroll">
                            <table class="cashier-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>SKU</th>
                                        <th class="cashier-num">Unit price</th>
                                        <th class="cashier-num">Qty</th>
                                        <th class="cashier-num">Line total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($order['lines'] as $line)
                                        <tr>
                                            <td>{{ $line['name'] }}</td>
                                            <td>{{ $line['sku'] }}</td>
                                            <td class="cashier-num">{{ $line['unitPrice'] }}</td>
                                            <td class="cashier-num">{{ $line['quantity'] }}</td>
                                            <td class="cashier-num">{{ $line['lineTotal'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="cashier-total">
                            <span>Total</span>
                            <span>{{ $order['total'] }}</span>
                        </div>
                    @endif

                    <div class="cashier-actions">
                        @if ($order['isOpen'])
                            <x-filament::button
                                color="success"
                                wire:click="checkout"
                                wire:confirm="Complete this sale? Stock will be deducted and the sale cannot be changed afterwards."
                                wire:loading.attr="disabled"
                                wire:target="checkout"
                                :disabled="! $order['hasItems']"
                            >
                                Complete sale
                            </x-filament::button>
                        @endif

                        <x-filament::button color="gray" wire:click="newSale">
                            New sale
                        </x-filament::button>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
