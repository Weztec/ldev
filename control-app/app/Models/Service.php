<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name', 'is_running'];

    protected $casts = [
        'is_running' => 'boolean',
    ];
}
