<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'source_type', 'source_id', 'entry_type', 'signed_amount',
        'balance_before', 'balance_after', 'business_date', 'description', 'created_by',
        'reversal_of_entry_id', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'business_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }
}
