<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meme extends Model
{
    protected $fillable = [
        'name',
        'image_url',
        'public_id',
        'top_text',
        'bottom_text',
        'tags',
        'session_id',
    ];
}
