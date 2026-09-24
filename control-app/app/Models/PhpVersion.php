<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhpVersion extends Model
{
    protected $fillable = ['version', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
