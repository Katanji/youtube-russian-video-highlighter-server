<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CheckChannelsRequest;
use App\Models\Channel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ChannelController extends Controller
{
    private const API_KEYS = ['api_key_1', 'api_key_2', 'api_key_3'];
    private const CACHE_KEY_PREFIX = 'youtube_api_key_';
    private const ALL_KEYS_EXHAUSTED = 'all_youtube_api_keys_exhausted';

    public function checkChannels(CheckChannelsRequest $request): JsonResponse
    {
        $channelNames = $request->channel_ids;
        $existingChannels = Channel::whereIn('channel_name', $channelNames)->get()->keyBy('channel_name');

        if ($this->areAllKeysExhausted()) {
            return response()->json($existingChannels);
        }

        $missingChannelNames = array_diff($channelNames, $existingChannels->keys()->toArray());
        $client = new Client();

        if (count($missingChannelNames) === 0) {
            info('No new missing Channel Names');
        }

        foreach ($missingChannelNames as $channelName) {
            Log::info('Processing channel name:', [$channelName]);

            $apiKey = $this->getAvailableApiKey();
            if (!$apiKey) {
                $this->markAllKeysExhausted();
                return response()->json(['error' => 'All API keys are exhausted'], 429);
            }

            try {
                $this->processChannel($client, $channelName, $apiKey, $existingChannels);
            } catch (\Exception $e) {
                if ($e instanceof GuzzleException && $e->getCode() == 403) {
                    $this->markKeyAsExhausted($apiKey);
                    Log::warning('API key limit reached. Key marked as exhausted.', ['exhausted_key' => $apiKey]);
                } else {
                    Log::error('Error processing channel:', ['channelName' => $channelName, 'error' => $e->getMessage()]);
                }
            }

            usleep(100000); // 100ms delay
        }

        return response()->json($existingChannels);
    }

    private function processChannel(Client $client, string $channelName, string $apiKey, &$existingChannels): void
    {
        $searchUrl = 'https://www.googleapis.com/youtube/v3/search';
        $searchResponse = $client->get($searchUrl, [
            'query' => [
                'part' => 'snippet',
                'type' => 'channel',
                'q' => $channelName,
                'key' => config("services.youtube.$apiKey")
            ]
        ]);

        $searchData = json_decode($searchResponse->getBody()->getContents(), true);

        Log::info('Search API Response:', ['data' => $searchData]);

        if (!empty($searchData['items'])) {
            $channelId = $searchData['items'][0]['id']['channelId'];

            $url = 'https://www.googleapis.com/youtube/v3/channels';
            $response = $client->get($url, [
                'query' => [
                    'part' => 'snippet,brandingSettings',
                    'id' => $channelId,
                    'key' => config("services.youtube.$apiKey")
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('Channel API Response:', ['data' => $data]);

            if (!empty($data['items'])) {
                $item = $data['items'][0];
                $country = $item['brandingSettings']['channel']['country'] ?? null;

                Log::info('Channel data:', ['channelId' => $channelId, 'country_code' => $country]);

                $channel = Channel::updateOrCreate(
                    ['channel_name' => $channelName],
                    [
                        'channel_id' => $channelId,
                        'country_code' => $country
                    ]
                );

                $existingChannels->put($channelName, $channel);
            } else {
                Log::error('No channel info found:', ['channelId' => $channelId]);
            }
        } else {
            Log::info('Channel not found:', ['channelName' => $channelName]);
            Channel::create(['channel_name' => $channelName, 'channel_id' => $channelName, 'country_code' => null]);
        }
    }

    private function getAvailableApiKey(): ?string
    {
        foreach (self::API_KEYS as $key) {
            if (!Cache::has(self::CACHE_KEY_PREFIX . $key)) {
                return $key;
            }
        }
        return null;
    }

    private function markKeyAsExhausted(string $key): void
    {
        $expirationTime = $this->getExpirationTime();
        Cache::put(self::CACHE_KEY_PREFIX . $key, true, $expirationTime);
    }

    private function areAllKeysExhausted(): bool
    {
        return Cache::has(self::ALL_KEYS_EXHAUSTED);
    }

    private function markAllKeysExhausted(): void
    {
        $expirationTime = $this->getExpirationTime();
        Cache::put(self::ALL_KEYS_EXHAUSTED, true, $expirationTime);
    }

    private function getExpirationTime(): Carbon
    {
        $now = Carbon::now('America/Los_Angeles');
        return $now->copy()->addDay()->startOfDay();
    }
}
