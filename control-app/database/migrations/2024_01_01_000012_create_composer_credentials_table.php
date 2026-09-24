<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composer_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->string('username');
            $table->text('secret');
            $table->timestamps();
            $table->unique(['site_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composer_credentials');
    }
};
