<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('job_templates', function (Blueprint $table) {
            $table->id();
            $table->string('label', 80)->unique();
            $table->string('name', 40);
            $table->string('command', 500);
            $table->string('schedule', 60)->nullable();
            $table->unsignedTinyInteger('numprocs')->default(1);
            $table->unsignedSmallInteger('stopwaitsecs')->default(10);
            $table->boolean('autostart')->default(true);
            $table->boolean('autorestart')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_templates');
    }
};
