@php
    $money = fn ($amount): string => 'Rp ' . number_format((float) ($amount ?? 0), 0, ',', '.');

    $statusLabel = fn ($status): string => match ($status) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'rejected' => 'Rejected',
        default => ucfirst((string) $status),
    };

    $statusColor = fn ($status): string => match ($status) {
        'confirmed' => 'success',
        'rejected' => 'danger',
        default => 'warning',
    };

    $orderStatus = $order->status instanceof \BackedEnum
        ? $order->status->value
        : $order->status;

    $modeLabel = match ($order->payment_mode) {
        'direct' => 'Direct',
        'split_items' => 'Split by items',
        'split_equal' => 'Split equally',
        default => 'Not set',
    };
@endphp

<div class="space-y-4">
    {{-- Transaction summary --}}
    <x-filament::section heading="Transaction summary">
        <div class="grid grid-cols-2 gap-x-6 gap-y-4">
            <div>
                <p class="text-xs font-medium text-gray-500">Transaction</p>
                <p class="mt-1 font-semibold">#{{ $order->getKey() }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500">Date</p>
                <p class="mt-1">{{ $order->created_at?->format('d M Y, H:i') ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500">Business</p>
                <p class="mt-1 font-medium">{{ $order->business?->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500">Outlet</p>
                <p class="mt-1 font-medium">{{ $order->outlet?->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500">Status</p>
                <div class="mt-1">
                    <x-filament::badge :color="$orderStatus === 'completed' ? 'success' : 'warning'">
                        {{ ucfirst((string) $orderStatus) }}
                    </x-filament::badge>
                </div>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500">Payment mode</p>
                <p class="mt-1">{{ $modeLabel }}</p>
            </div>
        </div>

        <div class="mt-5 rounded-lg bg-gray-50 p-4 dark:bg-white/5">
            <p class="text-xs font-medium text-gray-500">Bill total</p>
            <p class="mt-1 text-2xl font-bold tracking-tight">{{ $money($order->bill_total) }}</p>
        </div>
    </x-filament::section>

    {{-- Items --}}
    <x-filament::section heading="Items">
        @if ($order->items->isEmpty())
            <p class="text-sm text-gray-500">No items recorded.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[620px] table-auto text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="w-[34%] py-3 pr-4 font-semibold">Product</th>
                            <th class="w-[22%] py-3 pr-4 font-semibold">SKU</th>
                            <th class="whitespace-nowrap px-3 py-3 text-right font-semibold">Price</th>
                            <th class="whitespace-nowrap px-3 py-3 text-right font-semibold">Qty</th>
                            <th class="whitespace-nowrap py-3 pl-3 text-right font-semibold">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="py-3 pr-4">
                                    <p class="font-medium">{{ $item->product_name }}</p>
                                    @if ($item->variant_name)
                                        <p class="mt-1 text-xs text-gray-500">{{ $item->variant_name }}</p>
                                    @endif
                                </td>
                                <td class="break-all py-3 pr-4 text-gray-500">{{ $item->sku ?: '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ $money($item->unit_price) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ number_format($item->quantity) }}</td>
                                <td class="whitespace-nowrap py-3 pl-3 text-right font-medium tabular-nums">
                                    {{ $money((float) $item->unit_price * $item->quantity) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Payments --}}
    <x-filament::section heading="Payments">
        @if ($order->payments->isEmpty())
            <p class="text-sm text-gray-500">No payment records.</p>
        @else
            <div class="space-y-3">
                @foreach ($order->payments as $payment)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold">
                                    {{ strtoupper($payment->method) }}
                                    @if ($payment->participant)
                                        <span class="font-normal text-gray-500">
                                            · Participant {{ $payment->participant->position }}
                                        </span>
                                    @endif
                                </p>
                                <p class="mt-1 text-xl font-bold tabular-nums">{{ $money($payment->amount) }}</p>
                            </div>
                            <x-filament::badge :color="$statusColor($payment->status)">
                                {{ $statusLabel($payment->status) }}
                            </x-filament::badge>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-gray-200 pt-3 text-sm dark:border-white/10">
                            @if ($payment->method === 'cash' && $payment->amount_received !== null)
                                <div>
                                    <dt class="text-xs text-gray-500">Cash received</dt>
                                    <dd class="mt-1 font-medium tabular-nums">{{ $money($payment->amount_received) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-500">Change</dt>
                                    <dd class="mt-1 font-medium tabular-nums">{{ $money($payment->change_amount) }}</dd>
                                </div>
                            @endif

                            @if ($payment->qris_reference)
                                <div class="col-span-2">
                                    <dt class="text-xs text-gray-500">QRIS reference</dt>
                                    <dd class="mt-1 break-all font-medium">{{ $payment->qris_reference }}</dd>
                                </div>
                            @endif

                            @if ($payment->confirmed_at)
                                <div class="col-span-2">
                                    <dt class="text-xs text-gray-500">Confirmed at</dt>
                                    <dd class="mt-1">{{ $payment->confirmed_at->format('d M Y, H:i') }}</dd>
                                </div>
                            @endif

                            @if ($payment->rejected_at)
                                <div class="col-span-2">
                                    <dt class="text-xs text-gray-500">Rejected at</dt>
                                    <dd class="mt-1">{{ $payment->rejected_at->format('d M Y, H:i') }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Split bill --}}
    @if ($order->participants->isNotEmpty())
        <x-filament::section heading="Split bill">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($order->participants as $participant)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-semibold">Participant {{ $participant->position }}</p>
                            <p class="whitespace-nowrap font-bold tabular-nums">{{ $money($participant->total_amount) }}</p>
                        </div>

                        @if ($participant->itemAllocations->isNotEmpty())
                            <div class="mt-3 border-t border-gray-200 pt-3 dark:border-white/10">
                                <p class="mb-2 text-xs font-medium text-gray-500">Allocated items</p>
                                <ul class="space-y-2 text-sm">
                                    @foreach ($participant->itemAllocations as $allocation)
                                        <li class="flex justify-between gap-3">
                                            <span>
                                                {{ $allocation->orderItem?->product_name ?? 'Item' }}
                                                @if ($allocation->orderItem?->variant_name)
                                                    <span class="text-gray-500">({{ $allocation->orderItem->variant_name }})</span>
                                                @endif
                                            </span>
                                            <span class="whitespace-nowrap text-gray-500">× {{ $allocation->quantity }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="mt-3 space-y-2 border-t border-gray-200 pt-3 dark:border-white/10">
                            @forelse ($participant->payments as $payment)
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span>
                                        {{ strtoupper($payment->method) }}
                                        <span class="text-gray-500">· {{ $statusLabel($payment->status) }}</span>
                                    </span>
                                    <span class="whitespace-nowrap font-medium tabular-nums">{{ $money($payment->amount) }}</span>
                                </div>
                            @empty
                                <p class="text-sm text-gray-500">No payment records for this participant.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</div>
