<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expired_at
 */
class YouTubeApiKey extends Model
{
    use HasFactory;

    protected $table = 'youtube_api_keys';

    protected $fillable = ['name', 'key', 'expired_at'];

    protected $casts = [
        'expired_at' => 'datetime'
    ];

    public function scopeAvailable($query)
    {
        return $query->where(function($q) {
            $q->whereNull('expired_at')
              ->orWhere('expired_at', '<', now('America/Los_Angeles')->startOfDay()->utc());
        });
    }

    public function markAsExhausted(): void
    {
        $this->expired_at = now('UTC');
        $this->save();
    }
}
