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

        foreach ($missingChannelNames as $channelName) {
            $apiKey = null;
            
            // Try to get YouTube API key if available
            if (!$this->youtubeApiKeyService->areAllKeysExhausted()) {
                $apiKey = $this->youtubeApiKeyService->getAvailableApiKey();
            }

            try {
                $this->processChannel($channelName, $apiKey, $existingChannels);
            } catch (\Exception $e) {
                if ($e instanceof GuzzleException && $e->getCode() == 403) {
                    $this->youtubeApiKeyService->markKeyAsExhausted($apiKey);
                    Log::warning('API key limit reached. Key marked as exhausted.', ['exhausted_key' => $apiKey]);
                } else {
                    Log::error('Error processing channel:', ['channelName' => $channelName, 'error' => $e->getMessage()]);
                }
            }

            usleep(100000); // 100ms delay
        }

        return response()->json($existingChannels);
    }

    private function processChannel(string $channelName, string $apiKey, &$existingChannels): void
    {
        $existingChannel = Channel::where('channel_id', $channelName)->first();
        if ($existingChannel) {
            $existingChannels->put($channelName, $existingChannel);
            return;
        }

        // Try YouTube API first (free)
        if (!empty($apiKey)) {
            try {
                $this->processChannelViaYouTubeApi($channelName, $apiKey, $existingChannels);
                return;
            } catch (\Exception $e) {
                Log::warning('YouTube API failed, trying Apify fallback', [
                    'channel' => $channelName,
                    'error' => $e->getMessage()
                ]);
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

        Log::warning('Failed to get channel info from both YouTube API and Apify', [
            'channel' => $channelName
        ]);
    }

    private function saveChannelFromApify(string $channelName, array $apifyData, &$existingChannels): void
    {
        $existingChannel = Channel::where('channel_id', $apifyData['channel_id'])->first();
        
        if ($existingChannel && $existingChannel->channel_name === $existingChannel->channel_id) {
            $existingChannel->update([
                'channel_name' => $channelName,
                'country_code' => $apifyData['country_code'] ?? null
            ]);
            $channel = $existingChannel;
        } else {
            $channel = Channel::updateOrCreate(
                ['channel_name' => $channelName],
                [
                    'channel_id' => $apifyData['channel_id'],
                    'country_code' => $apifyData['country_code'] ?? null
                ]
            );
        }
        
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

                $existingChannel = Channel::where('channel_id', $channelId)->first();
                if ($existingChannel && $existingChannel->channel_name === $existingChannel->channel_id) {
                    $existingChannel->update([
                        'channel_name' => $channelName,
                        'country_code' => $country
                    ]);
                    $channel = $existingChannel;
                } else {
                    $channel = Channel::updateOrCreate(
                        ['channel_name' => $channelName],
                        [
                            'channel_id' => $channelId,
                            'country_code' => $country
                        ]
                    );
                }

                $existingChannels->put($channelName, $channel);
            } else {
                Log::error('No channel info found:', ['channelId' => $channelId]);
            }
        } else {
            Log::info('Channel not found:', ['channelName' => $channelName]);
            Channel::updateOrCreate(
                ['channel_name' => $channelName],
                ['channel_id' => $channelName, 'country_code' => null]
            );
        }
    }
}
