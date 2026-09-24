<?php

use Illuminate\Support\Facades\Route;
use App\Models\Site;
use App\Services\DatabaseBackupManager;

Route::livewire('/', 'pages::sites')->name('dashboard');

Route::livewire('/sites/{site}', 'pages::site-detail')->name('site-detail');
Route::livewire('/sites/{site}/settings', 'pages::site-settings')->name('site-settings');
Route::livewire('/services', 'pages::services-panel')->name('services');
Route::livewire('/new-project', 'pages::new-project-wizard')->name('new-project');
Route::livewire('/logs', 'pages::log-viewer')->name('logs');
Route::livewire('/php-versions', 'pages::php-versions')->name('php-versions');
Route::livewire('/databases', 'pages::databases')->name('databases');
Route::livewire('/repositories', 'pages::repositories')->name('repositories');
Route::livewire('/settings', 'pages::settings')->name('settings');
Route::livewire('/help', 'pages::help')->name('help');

Route::get('/sites/{site}/backups/{filename}/download', function (Site $site, string $filename) {
    $manager = new DatabaseBackupManager;
    $backups = collect($manager->list($site))->pluck('name');

    abort_unless($backups->contains($filename), 404);

    return response()->download(
        config('ldev.backups_dir') . '/' . $site->name . '/' . basename($filename)
    );
})->name('backup-download');
