<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Database extends Model
{
    protected $table = 'databases';

    protected $fillable = ['name', 'type', 'charset', 'collation'];
}
