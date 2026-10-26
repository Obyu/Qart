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
        .cashier-badge--cancelled { background: rgba(239, 68, 68, .15); border-color: rgba(239, 68, 68, .6); }
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

        $billConfigured = $order !== null
            && ($order['billConfigured'] ?? false);

        $isDirect = $paymentMode === \App\Services\PaymentService::MODE_DIRECT;
        $isEqualSplit = $paymentMode === \App\Services\PaymentService::MODE_SPLIT_EQUAL;
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
                        @elseif ($order['isCancelled'])
                            <span class="cashier-badge cashier-badge--cancelled">Cancelled {{ $order['cancelledAt'] }}</span>
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
                            <span>Rp {{ $order['total'] }}</span>
                        </div>
                    @endif

                    @if ($order['isOpen'] && $order['hasItems'])
                        <x-filament::section>
                            <x-slot name="heading">Payment setup</x-slot>

                            @if (! $billConfigured)
                                <p class="cashier-hint" style="margin-bottom: .75rem;">
                                    Choose how this bill should be paid. Once created, the bill and its items cannot be changed.
                                </p>

                                <div class="cashier-grid">
                                    <div class="cashier-field">
                                        <label for="cashier-payment-mode">Payment mode</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select id="cashier-payment-mode" wire:model.live="paymentMode">
                                                <option value="direct">Direct payment</option>
                                                <option value="split_equal">Split equally</option>
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>

                                    @if ($isEqualSplit)
                                        <div class="cashier-field">
                                            <label for="cashier-participant-names">Participant names</label>
                                            <x-filament::input.wrapper>
                                                <textarea
                                                    id="cashier-participant-names"
                                                    wire:model="participantNames"
                                                    rows="3"
                                                    maxlength="10000"
                                                    placeholder="One name per line"
                                                    class="w-full border-0 bg-transparent"
                                                ></textarea>
                                            </x-filament::input.wrapper>
                                            <p class="cashier-hint">Enter one participant per line.</p>
                                        </div>
                                    @endif
                                </div>

                                <div class="cashier-actions">
                                    <x-filament::button
                                        wire:click="configureBill"
                                        wire:loading.attr="disabled"
                                        wire:target="configureBill"
                                    >
                                        Create bill
                                    </x-filament::button>
                                </div>
                            @else
                                <p class="cashier-hint">Bill created. Payment mode: {{ $order['paymentMode'] ?? $paymentMode }}.</p>

                                @if ($participants !== [])
                                    <div class="cashier-field" style="margin-top: .75rem;">
                                        <label for="cashier-participant">Participant to pay</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select id="cashier-participant" wire:model.live="selectedParticipantId">
                                                <option value="">Select a participant</option>
                                                @foreach ($participants as $participant)
                                                    <option value="{{ $participant['id'] }}">
                                                        {{ $participant['name'] }} — Rp {{ $participant['amount'] }}
                                                    </option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>
                                @endif

                                <div class="cashier-grid" style="margin-top: 1rem;">
                                    <div class="cashier-field">
                                        <label for="cashier-cash-received">Cash received (Rp)</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="cashier-cash-received"
                                                type="text"
                                                inputmode="decimal"
                                                wire:model="cashReceived"
                                                placeholder="e.g. 50000 or 50000.00"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>
                                    <div>
                                        <x-filament::button
                                            wire:click="receiveCash"
                                            wire:loading.attr="disabled"
                                            wire:target="receiveCash"
                                        >
                                            Receive cash
                                        </x-filament::button>
                                    </div>
                                </div>

                                <div class="cashier-grid" style="margin-top: 1rem;">
                                    <div class="cashier-field">
                                        <label for="cashier-qris-reference">QRIS reference (optional)</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                id="cashier-qris-reference"
                                                type="text"
                                                maxlength="150"
                                                wire:model="qrisReference"
                                                placeholder="Reference from payment receipt"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>
                                    <div>
                                        <x-filament::button
                                            color="gray"
                                            wire:click="requestQris"
                                            wire:loading.attr="disabled"
                                            wire:target="requestQris"
                                        >
                                            Create QRIS payment
                                        </x-filament::button>
                                    </div>
                                </div>

                                <p class="cashier-hint" style="margin-top: .75rem;">
                                    QRIS payments remain pending until an authorized user verifies the actual payment and confirms it here.
                                </p>
                            @endif
                        </x-filament::section>

                        @if ($participants !== [])
                            <x-filament::section>
                                <x-slot name="heading">Split participants</x-slot>
                                <div class="cashier-scroll">
                                    <table class="cashier-table">
                                        <thead>
                                            <tr>
                                                <th>Participant</th>
                                                <th class="cashier-num">Amount due</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($participants as $participant)
                                                <tr>
                                                    <td>{{ $participant['name'] }}</td>
                                                    <td class="cashier-num">Rp {{ $participant['amount'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </x-filament::section>
                        @endif

                        @if ($payments !== [])
                            <x-filament::section>
                                <x-slot name="heading">Payment history</x-slot>
                                <div class="cashier-scroll">
                                    <table class="cashier-table">
                                        <thead>
                                            <tr>
                                                <th>Participant</th>
                                                <th>Method</th>
                                                <th>Status</th>
                                                <th class="cashier-num">Amount</th>
                                                <th class="cashier-num">Received</th>
                                                <th class="cashier-num">Change</th>
                                                <th>QRIS reference</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($payments as $payment)
                                                <tr>
                                                    <td>{{ $payment['participant'] ?? 'Direct bill' }}</td>
                                                    <td>{{ strtoupper($payment['method']) }}</td>
                                                    <td>{{ ucfirst($payment['status']) }}</td>
                                                    <td class="cashier-num">Rp {{ $payment['amount'] }}</td>
                                                    <td class="cashier-num">{{ $payment['amountReceived'] === null ? '—' : 'Rp '.$payment['amountReceived'] }}</td>
                                                    <td class="cashier-num">Rp {{ $payment['change'] }}</td>
                                                    <td>{{ $payment['qrisReference'] ?: '—' }}</td>
                                                    <td>
                                                        @if ($payment['method'] === 'qris' && $payment['status'] === 'pending')
                                                            <div class="cashier-actions" style="margin-top: 0;">
                                                                <x-filament::button
                                                                    size="sm"
                                                                    color="success"
                                                                    wire:click="confirmQris({{ $payment['id'] }})"
                                                                    wire:confirm="Have you verified that this QRIS payment was actually received?"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="confirmQris"

                                                                >
                                                                    Confirm
                                                                </x-filament::button>
                                                                <x-filament::button
                                                                    size="sm"
                                                                    color="danger"
                                                                    wire:click="rejectQris({{ $payment['id'] }})"
                                                                    wire:confirm="Reject this pending QRIS payment?"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="rejectQris"

                                                                >
                                                                    Reject
                                                                </x-filament::button>
                                                            </div>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </x-filament::section>
                        @endif
                    @endif

                    @if ($order['isOpen'])
                        <x-filament::section>
                            <x-slot name="heading">Cancel sale</x-slot>

                            <p class="cashier-hint" style="margin-bottom: .75rem;">
                                Cancellation is permanent. Pending QRIS payments will be rejected,
                                and a sale with a confirmed payment cannot be cancelled here.
                            </p>

                            <div class="cashier-field">
                                <label for="cashier-cancellation-reason">Reason for cancellation</label>
                                <x-filament::input.wrapper>
                                    <textarea
                                        id="cashier-cancellation-reason"
                                        wire:model="cancellationReason"
                                        rows="3"
                                        maxlength="500"
                                        placeholder="Explain why this sale is being cancelled"
                                        class="w-full border-0 bg-transparent"
                                    ></textarea>
                                </x-filament::input.wrapper>
                                <p class="cashier-hint">Required. Maximum 500 characters.</p>
                            </div>

                            <div class="cashier-actions">
                                <x-filament::button
                                    color="danger"
                                    wire:click="cancelOrder"
                                    wire:confirm="Cancel this sale? This action cannot be undone."
                                    wire:loading.attr="disabled"
                                    wire:target="cancelOrder"
                                >
                                    Cancel sale
                                </x-filament::button>
                            </div>
                        </x-filament::section>
                    @elseif ($order['isCancelled'])
                        <x-filament::section>
                            <x-slot name="heading">Cancellation details</x-slot>

                            <p>
                                <strong>Cancelled at:</strong>
                                {{ $order['cancelledAt'] }}
                            </p>
                            <p style="margin-top: .5rem;">
                                <strong>Reason:</strong>
                                {{ $order['cancellationReason'] }}
                            </p>
                        </x-filament::section>
                    @endif

                    <div class="cashier-actions">
                        <x-filament::button color="gray" wire:click="newSale">
                            New sale
                        </x-filament::button>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
