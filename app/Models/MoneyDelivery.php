<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyDelivery extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'manual_result_id',
        'suggested_amount',
        'delivered_amount',
        'business_date',
        'reason',
        'notes',
        'status',
        'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'suggested_amount' => 'decimal:2',
            'delivered_amount' => 'decimal:2',
            'business_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function manualResult(): BelongsTo
    {
        return $this->belongsTo(ManualResult::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
