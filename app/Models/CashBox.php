<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashBox extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'description',
        'balance',
        'currency_code',
        'is_default',
        'status',
        'created_by',
    ];

    protected $appends = ['creator_name'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getCreatorNameAttribute(): ?string
    {
        return $this->creator?->name ?? ($this->created_by ? 'Usuario #' . $this->created_by : null);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
