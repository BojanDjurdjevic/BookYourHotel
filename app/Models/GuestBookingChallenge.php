<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestBookingChallenge extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['id', 'email', 'payload', 'code_hash'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
