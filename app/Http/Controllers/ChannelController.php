<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckChannelsRequest;
use App\Models\Channel;
use App\Services\YouTubeApiKeyService;
use App\Services\ApifyService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChannelController extends Controller
{
    private YouTubeApiKeyService $youtubeApiKeyService;
    private ApifyService $apifyService;
    private Client $client;

    public function __construct(YouTubeApiKeyService $youtubeApiKeyService, ApifyService $apifyService, Client $client)
    {
        $this->youtubeApiKeyService = $youtubeApiKeyService;
        $this->apifyService = $apifyService;
        $this->client = $client;
    }

    public function checkChannels(CheckChannelsRequest $request): JsonResponse
    {
        $channelNames = $request->channel_ids;
        $existingChannels = Channel::whereIn('channel_name', $channelNames)->get()->keyBy('channel_name');
        $missingChannelNames = array_diff($channelNames, $existingChannels->keys()->toArray());

        $allKeysExhausted = $this->youtubeApiKeyService->areAllKeysExhausted();
        if ($allKeysExhausted) {
            Log::info('All YouTube API keys exhausted, using Apify for all channels');
        }

        foreach ($missingChannelNames as $channelName) {
            $apiKey = null;

            // Try to get YouTube API key if available
            if (!$allKeysExhausted) {
                $apiKey = $this->youtubeApiKeyService->getAvailableApiKey();
                if (!$apiKey) {
                    $allKeysExhausted = true;
                    Log::info('YouTube API keys exhausted during processing, switching to Apify');
                }
            }

            try {
                $this->processChannel($channelName, $apiKey, $existingChannels);
            } catch (\Exception $e) {
                if ($e instanceof GuzzleException && $e->getCode() == 403) {
                    $this->youtubeApiKeyService->markKeyAsExhausted($apiKey);
                } else {
                    Log::error('Error processing channel:', ['channelName' => $channelName, 'error' => $e->getMessage()]);
                }
            }

            usleep(100000); // 100ms delay
        }

        return response()->json($existingChannels);
    }

    private function processChannel(string $channelName, ?string $apiKey, &$existingChannels): void
    {
        $existingChannel = Channel::where('channel_id', $channelName)->first();
        if ($existingChannel) {
            $existingChannels->put($channelName, $existingChannel);
            return;
        }

        // Prevent repeated API calls for the same channel within 5 minutes
        $cacheKey = "processing_channel_{$channelName}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, 300); // 5 minutes

        // Try YouTube API first (free) - only if we have a valid API key
        if (!empty($apiKey)) {
            try {
                $this->processChannelViaYouTubeApi($channelName, $apiKey, $existingChannels);
                return;
            } catch (\Exception $e) {
                // Check if this is a quota exceeded error
                if (str_contains($e->getMessage(), 'exceeded your quota') ||
                    str_contains($e->getMessage(), '403 Forbidden')) {
                    $this->youtubeApiKeyService->markKeyAsExhausted($apiKey);
                } else {
                    Log::warning('YouTube API failed, trying Apify fallback', [
                        'channel' => $channelName,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Fallback to Apify if YouTube API failed or no keys available
        if ($this->apifyService->isAvailable()) {
            $apifyData = $this->apifyService->getChannelInfo($channelName);

            if ($apifyData && $apifyData['channel_id']) {
                Log::info('Channel info obtained from Apify (fallback)', ['channel' => $channelName]);
                $this->saveChannelFromApify($channelName, $apifyData, $existingChannels);
                return;
            }
        }

        // Only log this occasionally to avoid log spam
        if (rand(1, 10) === 1) { // Log only 10% of failures
            Log::info('Channel not found in both YouTube API and Apify', [
                'channel' => $channelName
            ]);
        }
    }

    private function saveChannelFromApify(string $channelName, array $apifyData, &$existingChannels): void
    {
        // Always use channel_id as primary key to avoid duplicate key conflicts
        $channel = Channel::updateOrCreate(
            ['channel_id' => $apifyData['channel_id']],
            [
                'channel_name' => $channelName,
                'country_code' => $apifyData['country_code'] ?? null
            ]
        );

        $existingChannels->put($channelName, $channel);
    }

    /**
     * Check if string is a YouTube Channel ID
     */
    private function isChannelId(string $identifier): bool
    {
        return preg_match('/^UC[a-zA-Z0-9_-]{22}$/', $identifier) === 1;
    }

    /**
     * Process channel via YouTube API (fallback method)
     */
    private function processChannelViaYouTubeApi(string $channelName, string $apiKey, &$existingChannels): void
    {
        $channelId = null;
        if ($this->isChannelId($channelName)) {
            $channelId = $channelName;
        } else {
            $searchUrl = 'https://www.googleapis.com/youtube/v3/search';
            $searchResponse = $this->client->get($searchUrl, [
                'query' => [
                    'part' => 'id',
                    'type' => 'channel',
                    'q' => $channelName,
                    'key' => $apiKey
                ]
            ]);

            $searchData = json_decode($searchResponse->getBody()->getContents(), true);

            if (!empty($searchData['items'])) {
                $channelId = $searchData['items'][0]['id']['channelId'];
            }
        }

        if ($channelId) {
            $url = 'https://www.googleapis.com/youtube/v3/channels';
            $response = $this->client->get($url, [
                'query' => [
                    'part' => 'snippet,brandingSettings',
                    'id' => $channelId,
                    'key' => $apiKey
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!empty($data['items'])) {
                $item = $data['items'][0];
                $country = $item['brandingSettings']['channel']['country'] ?? null;

                // Always use channel_id as primary key to avoid duplicate key conflicts
                $channel = Channel::updateOrCreate(
                    ['channel_id' => $channelId],
                    [
                        'channel_name' => $channelName,
                        'country_code' => $country
                    ]
                );

                $existingChannels->put($channelName, $channel);
            } else {
                Log::error('No channel info found:', ['channelId' => $channelId]);
            }
        } else {
            Log::info('Channel not found:', ['channelName' => $channelName]);
            // For not found channels, use channel_name as both name and id
            Channel::updateOrCreate(
                ['channel_id' => $channelName],
                ['channel_name' => $channelName, 'country_code' => null]
            );
        }
    }
}
