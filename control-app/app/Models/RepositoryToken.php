<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepositoryToken extends Model
{
    protected $fillable = ['provider', 'token', 'username', 'expires_at'];

    protected $casts = [
        'token' => 'encrypted',
        'expires_at' => 'date',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresSoon(int $withinDays = 30): bool
    {
        return $this->expires_at !== null
            && !$this->isExpired()
            && $this->daysUntilExpiry() <= $withinDays;
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expires_at !== null ? (int) now()->diffInDays($this->expires_at) : null;
    }
}
