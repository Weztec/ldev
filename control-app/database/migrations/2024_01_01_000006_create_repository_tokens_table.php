<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repository_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->text('token');
            $table->date('expires_at')->nullable();
            $table->string('username');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_tokens');
    }
};
