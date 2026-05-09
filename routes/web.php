<?php

use Illuminate\Support\Facades\Route;
// Page de partage d'un meme (pour les réseaux sociaux)
Route::get('/share/{id}', function ($id) {
    $meme = \App\Models\Meme::findOrFail($id);

    $title = e($meme->name ?? 'Meme');
    $imageUrl = e($meme->image_url);
    $text = e(trim(($meme->top_text ?? '') . ' ' . ($meme->bottom_text ?? '')));
    $appUrl = env('FRONTEND_URL', 'http://localhost:5173');

    return response("<!DOCTYPE html>
                    <html>
                        <head>
                            <meta charset='utf-8'>

                            <!-- Open Graph (Facebook, LinkedIn, Discord…) -->
                            <meta property='og:type'        content='website'>
                            <meta property='og:title'       content='{$title}'>
                            <meta property='og:description' content='{$text}'>
                            <meta property='og:image'       content='{$imageUrl}'>
                            <meta property='og:image:width'  content='1200'>
                            <meta property='og:image:height' content='630'>

                            <!-- Twitter Card -->
                            <meta name='twitter:card'        content='summary_large_image'>
                            <meta name='twitter:title'       content='{$title}'>
                            <meta name='twitter:description' content='{$text}'>
                            <meta name='twitter:image'       content='{$imageUrl}'>

                            <!-- Redirige l'utilisateur humain vers le frontend -->
                            <meta http-equiv='refresh' content='0; url={$appUrl}'>
                        </head>
                        <body>
                            <a href='{$appUrl}'>Redirecting…</a>
                        </body>
                    </html>", 200, ['Content-Type' => 'text/html']);
})->name('meme.share');
