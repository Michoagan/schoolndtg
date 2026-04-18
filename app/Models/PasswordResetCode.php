<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordResetCode extends Model
{
    use HasFactory;

    protected $table = 'password_reset_codes';

    protected $fillable = [
        'email',
        'code',
        'created_at',
        'expires_at'
    ];

    const UPDATED_AT = null;

    protected $casts = [
        'expires_at' => 'datetime'
    ];

    /**
     * Check if code is expired
     */
    public function isExpired()
    {
        return $this->expires_at->isPast();
    }

    /**
     * Scope for valid codes
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }
}