<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = ['company_id', 'user_id', 'key', 'operation', 'request_hash', 'response_code', 'response_body', 'expires_at'];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'expires_at' => 'datetime'];
    }
}
