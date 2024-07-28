<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CheckChannelsRequest;
use App\Models\Channel;
use GuzzleHttp\Client;

class ChannelController extends Controller
{
    public function checkChannels(CheckChannelsRequest $request): array
    {
        $client = new Client();
        $channelIds = $request->channel_ids;
        $channels = Channel::whereIn('channel_id', $channelIds)->get()->keyBy('channel_id');

        $missingChannelIds = array_diff($channelIds, $channels->keys()->toArray());

        if (!empty($missingChannelIds)) {
            $url = 'https://www.googleapis.com/youtube/v3/channels';
            $response = $client->get($url, [
                'query' => [
                    'part' => 'snippet',
                    'id' => implode(',', $missingChannelIds),
                    'key' => config('services.youtube.api_key')
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            foreach ($data['items'] as $item) {
                $country = $item['snippet']['country'] ?? null;
                $channelId = $item['id'];

                $channel = Channel::updateOrCreate(
                    ['channel_id' => $channelId],
                    ['country' => $country]
                );

                $channels->put($channelId, $channel);
            }
        }

        return [$channels];
    }
}
