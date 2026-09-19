<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'description', 'route', 'operator_name', 'status', 'current_balance', 'created_by'];

    protected function casts(): array
    {
        return ['current_balance' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function weeklySettlements(): HasMany
    {
        return $this->hasMany(WeeklySettlement::class);
    }
}
