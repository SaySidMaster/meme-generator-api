<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20);
            $table->string('image_url');          // URL Cloudinary complète
            $table->string('public_id');          // ID Cloudinary (pour suppression)
            $table->string('top_text', 20)->nullable();
            $table->string('bottom_text', 20)->nullable();
            $table->string('tags', 20)->nullable();
            $table->string('session_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memes');
    }
};
