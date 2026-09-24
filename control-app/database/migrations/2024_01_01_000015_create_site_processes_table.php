<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('name', 40);
            $table->string('command', 500);

            $table->string('schedule', 60)->nullable();
            $table->unsignedTinyInteger('numprocs')->default(1);
            $table->unsignedSmallInteger('stopwaitsecs')->default(10);
            $table->boolean('autostart')->default(true);
            $table->boolean('autorestart')->default(true);

            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'name']);
        });

        Schema::table('sites', function (Blueprint $table) {

            $table->text('supervisor_extra')->nullable()->after('db_auto_backup_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('supervisor_extra');
        });
        Schema::dropIfExists('site_processes');
    }
};
