<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ComposerCredential extends Model
{
    protected $fillable = ['site_id', 'host', 'username', 'secret'];

    protected $casts = [
        'secret' => 'encrypted',
    ];

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('site_id');
    }

    public function scopeForSite(Builder $query, Site $site): Builder
    {
        return $query->where('site_id', $site->id);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
