<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property array $channel_ids
 */
class CheckChannelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel_ids' => 'required|array',
            'channel_ids.*' => 'required|string|distinct',
        ];
    }
}
