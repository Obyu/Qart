@php
    $rupiah = function ($amount): string {
        $whole = explode('.', (string) ($amount ?? '0'))[0];

        return 'Rp ' . number_format((int) ($whole === '' ? 0 : $whole), 0, ',', '.');
    };
@endphp

<x-filament-panels::page>
    <x-filament::section
        heading="Insight bisnis"
        description="Dibuat dari ringkasan penjualan bisnis Anda. Hanya angka agregat yang dikirim ke AI."
    >
        <x-filament::button
            wire:click="generate"
            wire:loading.attr="disabled"
            wire:target="generate"
        >
            Buat insight
        </x-filament::button>

        <p wire:loading wire:target="generate" style="margin-top: 1rem;">
            Menyusun insight...
        </p>

        @if ($insightText !== null)
            <div style="margin-top: 1.5rem;">
                <x-filament::badge :color="$insightSource === 'ai' ? 'success' : 'warning'">
                    {{ $insightSource === 'ai' ? 'Dibuat oleh AI' : 'Ringkasan otomatis (tanpa AI)' }}
                </x-filament::badge>

                <p style="margin-top: 0.75rem; white-space: pre-line;">{{ $insightText }}</p>
            </div>
        @endif
    </x-filament::section>

    @if ($summary !== null)
        <x-filament::section heading="Data yang dianalisis">
            <p>Total pendapatan: {{ $rupiah($summary['total_revenue'] ?? 0) }}</p>
            <p>Transaksi selesai: {{ (int) ($summary['completed_orders'] ?? 0) }}</p>
            <p>Order terbuka: {{ (int) ($summary['open_orders'] ?? 0) }}</p>
            <p>Pembayaran terkonfirmasi: {{ $rupiah($summary['confirmed_payments'] ?? 0) }}</p>
            <p>QRIS menunggu konfirmasi: {{ (int) ($summary['pending_qris_payments'] ?? 0) }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
