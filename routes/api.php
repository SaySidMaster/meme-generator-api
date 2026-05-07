<?php

use App\Http\Controllers\MemeController;
use Illuminate\Support\Facades\Route;

Route::get('/memes',       [MemeController::class, 'index']);   // Galerie React
Route::post('/generate',   [MemeController::class, 'store']);   // Création
Route::delete('/deleteMeme', [MemeController::class, 'destroy']); // Suppression (dev)
