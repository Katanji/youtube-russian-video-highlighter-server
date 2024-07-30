<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CheckChannelsRequest;
use App\Models\Channel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    public function checkChannels(CheckChannelsRequest $request): JsonResponse
    {
        $channelNames = $request->channel_ids;
        $existingChannels = Channel::whereIn('channel_name', $channelNames)->get()->keyBy('channel_name');
        $missingChannelNames = array_diff($channelNames, $existingChannels->keys()->toArray());
        $client = new Client();

        if (count($missingChannelNames) === 0) info('no new missing Channel Names');

        foreach ($missingChannelNames as $channelName) {
            Log::info('Processing channel name:', [$channelName]);

            try {
                $searchUrl = 'https://www.googleapis.com/youtube/v3/search';
                $searchResponse = $client->get($searchUrl, [
                    'query' => [
                        'part' => 'snippet',
                        'type' => 'channel',
                        'q' => $channelName,
                        'key' => config('services.youtube.api_key')
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
                            'key' => config('services.youtube.api_key')
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

                usleep(100000); // 100ms delay
            } catch (\Exception $e) {
                Log::error('Error processing channel:', ['channelName' => $channelName, 'error' => $e->getMessage()]);
            } catch (GuzzleException $e) {
            }
        }

        return response()->json($existingChannels);
    }
}
