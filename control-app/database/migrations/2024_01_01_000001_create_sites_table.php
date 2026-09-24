<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('domain')->unique();
            $table->string('document_root');
            $table->string('php_version')->default('8.3');
            $table->string('node_version')->nullable();
            $table->boolean('xdebug_enabled')->default(false);
            $table->boolean('queue_worker_enabled')->default(false);
            $table->unsignedSmallInteger('queue_workers')->default(1);
            $table->unsignedSmallInteger('queue_sleep')->default(3);
            $table->unsignedSmallInteger('queue_tries')->default(3);
            $table->unsignedInteger('queue_max_time')->default(3600);
            $table->string('queue_names')->default('default');
            $table->boolean('reverb_enabled')->default(false);
            $table->boolean('scheduler_enabled')->default(false);
            $table->boolean('db_auto_backup_enabled')->default(true);
            $table->json('environment_variables')->nullable();
            $table->text('custom_nginx_config')->nullable();
            $table->boolean('is_linked')->default(false);
            $table->boolean('is_parked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
