<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'amount', 'business_date', 'payment_method',
        'reference', 'notes', 'status', 'idempotency_key', 'created_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'business_date' => 'date', 'confirmed_at' => 'datetime'];
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
