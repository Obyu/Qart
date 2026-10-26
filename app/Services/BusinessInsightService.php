<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the aggregate sales summary into a short business insight.
 *
 * Only aggregate figures are sent to the AI provider. When the key is missing,
 * the request fails or the answer is empty, a clearly labelled rule-based
 * summary is returned instead, so the caller always gets text.
 */
class BusinessInsightService
{
    public const SOURCE_AI = 'ai';

    public const SOURCE_FALLBACK = 'fallback';

    /**
     * @param  array<string, mixed>  $summary  Output of BusinessAnalyticsService::salesSummary()
     * @return array{source: string, text: string}
     */
    public function generate(array $summary): array
    {
        $key = trim((string) config('insight.api_key'));

        if ($key === '') {
            return $this->fallback($summary);
        }

        try {
            $response = Http::timeout((int) config('insight.timeout', 20))
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => $key])
                ->post($this->endpoint(), [
                    'contents' => [[
                        'parts' => [['text' => $this->prompt($summary)]],
                    ]],
                    'generationConfig' => ['temperature' => 0.4],
                ]);

            if ($response->successful()) {
                $parts = $response->json('candidates.0.content.parts', []);
                $text = trim(collect(is_array($parts) ? $parts : [])
                    ->pluck('text')
                    ->filter()
                    ->implode("\n"));

                if ($text !== '') {
                    return ['source' => self::SOURCE_AI, 'text' => $text];
                }
            } else {
                Log::warning('Business insight request was rejected.', [
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Business insight request failed.', [
                'exception' => $e::class,
            ]);
        }

        return $this->fallback($summary);
    }

    private function endpoint(): string
    {
        return rtrim((string) config('insight.base_url'), '/')
            .'/models/'.config('insight.model').':generateContent';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function prompt(array $summary): string
    {
        $data = json_encode(
            Arr::only($summary, [
                'total_revenue',
                'completed_orders',
                'open_orders',
                'confirmed_payments',
                'pending_qris_payments',
            ]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return 'Kamu adalah analis bisnis untuk pemilik toko kecil. '
            .'Berdasarkan ringkasan penjualan berikut (mata uang rupiah), tulis insight '
            .'dalam bahasa Indonesia yang sederhana: 3 poin singkat tentang kondisi bisnis '
            .'dan 1 saran tindakan untuk minggu ini. Maksimal 120 kata. '
            .'Gunakan hanya angka yang ada pada data dan jangan mengarang angka. '
            .'Jika data masih kosong, katakan bahwa data belum cukup. '
            .'Data: '.$data;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{source: string, text: string}
     */
    private function fallback(array $summary): array
    {
        $completed = (int) ($summary['completed_orders'] ?? 0);
        $open = (int) ($summary['open_orders'] ?? 0);
        $pendingQris = (int) ($summary['pending_qris_payments'] ?? 0);
        $revenue = $this->wholeRupiah((string) ($summary['total_revenue'] ?? '0'));

        $lines = [];

        if ($completed === 0) {
            $lines[] = 'Belum ada transaksi selesai. Selesaikan penjualan pertama untuk melihat insight.';
        } else {
            $lines[] = 'Total pendapatan '.$this->rupiah($revenue).' dari '.$completed.' transaksi selesai.';
            $lines[] = 'Rata-rata nilai transaksi sekitar '.$this->rupiah(intdiv($revenue, $completed)).'.';
        }

        if ($open > 0) {
            $lines[] = 'Ada '.$open.' order terbuka yang belum dibayar lunas.';
        }

        if ($pendingQris > 0) {
            $lines[] = 'Ada '.$pendingQris.' pembayaran QRIS yang menunggu konfirmasi kasir.';
        }

        return ['source' => self::SOURCE_FALLBACK, 'text' => implode("\n", $lines)];
    }

    private function wholeRupiah(string $amount): int
    {
        $whole = explode('.', trim($amount))[0];

        return ctype_digit($whole) ? (int) $whole : 0;
    }

    private function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
