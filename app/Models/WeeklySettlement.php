<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklySettlement extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'week_start', 'week_end', 'sales_amount',
        'prizes_amount', 'commission_rate', 'commission_amount',
        'cash_delivered_amount', 'weekly_balance',
        'balance_before', 'balance_after', 'notes', 'status', 'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'sales_amount' => 'decimal:2',
            'prizes_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'cash_delivered_amount' => 'decimal:2',
            'weekly_balance' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
