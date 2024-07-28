<?php

use App\Http\Controllers\ChannelController;
use Illuminate\Support\Facades\Route;

Route::post('/check-channels', [ChannelController::class, 'checkChannels'])->name('check-channels');
