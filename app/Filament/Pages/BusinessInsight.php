<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\BusinessAnalyticsService;
use App\Services\BusinessInsightService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class BusinessInsight extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI Insight';

    protected static ?string $title = 'AI Business Insight';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.business-insight';

    public ?string $insightText = null;

    public ?string $insightSource = null;

    /** @var array<string, mixed>|null */
    public ?array $summary = null;

    /**
     * Build the summary on the server from the signed-in user's own businesses,
     * then ask the insight service for text. Nothing from the browser is trusted.
     */
    public function generate(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $summary = app(BusinessAnalyticsService::class)->salesSummary($user);
        $result = app(BusinessInsightService::class)->generate($summary);

        $this->summary = $summary;
        $this->insightText = $result['text'];
        $this->insightSource = $result['source'];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isOwner();
    }
}
