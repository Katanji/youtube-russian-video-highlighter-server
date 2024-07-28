<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static updateOrCreate(array $array, null[] $array1)
 * @method static whereIn(string $string, array $channelIds)
 */
class Channel extends Model
{
    use HasFactory;
}
