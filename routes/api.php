<?php

use App\Http\Controllers\MemeController;
use Illuminate\Support\Facades\Route;

Route::get('/session',     [MemeController::class, 'sessionInfo']);
Route::get('/memes',       [MemeController::class, 'index']);
Route::post('/generate',   [MemeController::class, 'store']);
Route::delete('/deleteMeme', [MemeController::class, 'destroy']);
