<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckChannelsRequest;
use App\Models\Channel;
use App\Services\YouTubeApiKeyService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    private YouTubeApiKeyService $youtubeApiKeyService;
    private Client $client;

    public function __construct(YouTubeApiKeyService $youtubeApiKeyService, Client $client)
    {
        $this->youtubeApiKeyService = $youtubeApiKeyService;
        $this->client = $client;
    }

    public function checkChannels(CheckChannelsRequest $request): JsonResponse
    {
        $channelNames = $request->channel_ids;
        $existingChannels = Channel::whereIn('channel_name', $channelNames)->get()->keyBy('channel_name');

        if (!$this->youtubeApiKeyService->areAllKeysExhausted()) {
            $missingChannelNames = array_diff($channelNames, $existingChannels->keys()->toArray());

            foreach ($missingChannelNames as $channelName) {
                $apiKey = $this->youtubeApiKeyService->getAvailableApiKey();
                if (!$apiKey) {
                    Log::warning('All API keys are currently exhausted. Waiting for reset.');
                    break;
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
        } else {
            Log::info('All API keys are exhausted. Skipping API calls.');
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

        $searchUrl = 'https://www.googleapis.com/youtube/v3/search';
        $searchResponse = $this->client->get($searchUrl, [
            'query' => [
                'part' => 'snippet',
                'type' => 'channel',
                'q' => $channelName,
                'key' => $apiKey
            ]
        ]);

        $searchData = json_decode($searchResponse->getBody()->getContents(), true);

        if (!empty($searchData['items'])) {
            $channelId = $searchData['items'][0]['id']['channelId'];

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
