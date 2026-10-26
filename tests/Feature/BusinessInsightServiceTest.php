<?php

namespace Tests\Feature;

use App\Services\BusinessInsightService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessInsightServiceTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return [
            'total_revenue' => '150000.00',
            'completed_orders' => 3,
            'open_orders' => 1,
            'confirmed_payments' => '150000.00',
            'pending_qris_payments' => 2,
        ];
    }

    public function test_without_an_api_key_a_labelled_fallback_is_returned_and_nothing_is_sent(): void
    {
        config(['insight.api_key' => '']);
        Http::fake();

        $result = (new BusinessInsightService)->generate($this->summary());

        $this->assertSame(BusinessInsightService::SOURCE_FALLBACK, $result['source']);
        $this->assertStringContainsString('Rp 150.000', $result['text']);
        $this->assertStringContainsString('Rp 50.000', $result['text']);
        Http::assertNothingSent();
    }

    public function test_a_successful_answer_is_returned_as_ai_text_with_only_the_summary_sent(): void
    {
        config(['insight.api_key' => 'test-key', 'insight.model' => 'test-model']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Penjualan stabil.']]]]],
            ], 200),
        ]);

        $result = (new BusinessInsightService)->generate($this->summary());

        $this->assertSame(BusinessInsightService::SOURCE_AI, $result['source']);
        $this->assertSame('Penjualan stabil.', $result['text']);

        Http::assertSent(function (Request $request): bool {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($request->url(), 'test-model:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && str_contains($prompt, '150000.00');
        });
    }

    public function test_an_api_error_falls_back(): void
    {
        config(['insight.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $result = (new BusinessInsightService)->generate($this->summary());

        $this->assertSame(BusinessInsightService::SOURCE_FALLBACK, $result['source']);
    }

    public function test_a_connection_failure_falls_back(): void
    {
        config(['insight.api_key' => 'test-key']);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $result = (new BusinessInsightService)->generate($this->summary());

        $this->assertSame(BusinessInsightService::SOURCE_FALLBACK, $result['source']);
    }

    public function test_an_empty_answer_falls_back(): void
    {
        config(['insight.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['candidates' => []], 200)]);

        $result = (new BusinessInsightService)->generate($this->summary());

        $this->assertSame(BusinessInsightService::SOURCE_FALLBACK, $result['source']);
    }

    public function test_an_empty_business_gets_a_clear_message(): void
    {
        config(['insight.api_key' => '']);

        $result = (new BusinessInsightService)->generate([
            'total_revenue' => '0.00',
            'completed_orders' => 0,
            'open_orders' => 0,
            'confirmed_payments' => '0.00',
            'pending_qris_payments' => 0,
        ]);

        $this->assertStringContainsString('Belum ada transaksi selesai', $result['text']);
    }
}
