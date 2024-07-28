<?php

use App\Http\Controllers\ChannelController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/check-channels', [ChannelController::class, 'checkChannels'])->name('check-channels');
