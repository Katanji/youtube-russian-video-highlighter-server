<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\YouTubeApiKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class YouTubeApiKeyService
{
    private const ALL_KEYS_EXHAUSTED = 'all_youtube_api_keys_exhausted';

    public function getAvailableApiKey(): ?string
    {
        if (Cache::has(self::ALL_KEYS_EXHAUSTED)) {
            return null;
        }

        $key = YouTubeApiKey::available()->orderBy('expired_at')->first();

        return $key ? $key->key : null;
    }

    public function markKeyAsExhausted(string $key): void
    {
        $apiKey = YouTubeApiKey::where('key', $key)->first();
        if ($apiKey) {
            $apiKey->markAsExhausted();
        }

        if ($this->areAllKeysExhausted()) {
            $this->markAllKeysExhausted();
        }
    }

    public function areAllKeysExhausted(): bool
    {
        return Cache::has(self::ALL_KEYS_EXHAUSTED) || !YouTubeApiKey::available()->exists();
    }

    private function markAllKeysExhausted(): void
    {
        $tomorrowStart = Carbon::now('UTC')->addDay()->startOfDay();
        Cache::put(self::ALL_KEYS_EXHAUSTED, true, $tomorrowStart);
    }
}
