<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ApifyService
{
    private Client $client;
    private string $apiToken;
    
    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->apiToken = config('services.apify.token', '');
    }

    /**
     * Get YouTube channel information via Apify
     * 
     * @param string $channelIdentifier - can be channel ID, channel name or URL
     * @return array|null
     */
    public function getChannelInfo(string $channelIdentifier): ?array
    {
        try {
            $channelUrl = $this->normalizeChannelUrl($channelIdentifier);
            
            $actorId = 'streamers~youtube-channel-scraper';
            
            $input = [
                'startUrls' => [
                    ['url' => $channelUrl]
                ],
                'maxResults' => 1,
                'maxResultStreams' => 0,
                'maxResultsShorts' => 0
            ];

            $response = $this->client->post("https://api.apify.com/v2/acts/{$actorId}/run-sync-get-dataset-items", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json'
                ],
                'json' => $input,
                'query' => [
                    'timeout' => 120
                ]
            ]);

            $results = json_decode($response->getBody()->getContents(), true);

            if (empty($results)) {
                return null;
            }

            $channelData = $results[0];

            // Extract Channel ID from URL
            $channelId = $this->extractChannelIdFromUrl($channelData['channelUrl'] ?? '');
            
            $result = [
                'channel_id' => $channelId,
                'channel_name' => $channelData['channelName'] ?? null,
                'country_code' => $this->normalizeCountryCode($channelData['channelLocation'] ?? null),
                'subscribers' => $channelData['numberOfSubscribers'] ?? null,
                'description' => $channelData['channelDescription'] ?? null,
                'source' => 'apify'
            ];
            
            return $result;

        } catch (\Exception $e) {
            Log::error('Apify: Error getting channel info', [
                'channel' => $channelIdentifier,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Normalize channel URL for Apify
     */
    private function normalizeChannelUrl(string $channelIdentifier): string
    {
        if (str_starts_with($channelIdentifier, 'https://')) {
            return $channelIdentifier;
        }

        if (preg_match('/^UC[a-zA-Z0-9_-]{22}$/', $channelIdentifier)) {
            return "https://www.youtube.com/channel/{$channelIdentifier}";
        }

        if (str_starts_with($channelIdentifier, '@')) {
            return "https://www.youtube.com/{$channelIdentifier}";
        }

        return "https://www.youtube.com/@{$channelIdentifier}";
    }

    /**
     * Extract Channel ID from YouTube URL
     */
    private function extractChannelIdFromUrl(string $url): ?string
    {
        if (preg_match('/\/channel\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Normalize country code
     */
    private function normalizeCountryCode(?string $location): ?string
    {
        if (!$location) {
            return null;
        }
        $countryMap = [
            'United States' => 'US',
            'Russia' => 'RU',
            'Ukraine' => 'UA',
            'Germany' => 'DE',
            'France' => 'FR',
            'United Kingdom' => 'GB',
            'Canada' => 'CA',
            'Australia' => 'AU',
            'Japan' => 'JP',
            'South Korea' => 'KR',
            'China' => 'CN',
            'India' => 'IN',
            'Brazil' => 'BR',
            'Mexico' => 'MX',
            'Spain' => 'ES',
            'Italy' => 'IT',
            'Poland' => 'PL',
            'Netherlands' => 'NL',
            'Sweden' => 'SE',
            'Norway' => 'NO',
            'Denmark' => 'DK',
            'Finland' => 'FI',
            'Czechia' => 'CZ',
            'Czech Republic' => 'CZ'
        ];

        return $countryMap[$location] ?? strtoupper(substr($location, 0, 2));
    }

    /**
     * Check if Apify service is available
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiToken);
    }
}
