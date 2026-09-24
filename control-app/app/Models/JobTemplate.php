<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobTemplate extends Model
{
    protected $fillable = [
        'label',
        'name',
        'command',
        'schedule',
        'numprocs',
        'stopwaitsecs',
        'autostart',
        'autorestart',
    ];

    protected $casts = [
        'autostart' => 'boolean',
        'autorestart' => 'boolean',
    ];
}
