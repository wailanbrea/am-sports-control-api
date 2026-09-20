<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'phone',
        'owner_name',
        'owner_phone',
        'owner_whatsapp',
        'owner_email',
        'owner_document',
        'manager_name',
        'manager_phone',
        'manager_whatsapp',
        'address',
        'sector',
        'city',
        'province',
        'location_reference',
        'latitude',
        'longitude',
        'collection_day',
        'description',
        'route',
        'operator_name',
        'status',
        'current_balance',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
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

    public function manualResults(): HasMany
    {
        return $this->hasMany(ManualResult::class);
    }

    public function moneyDeliveries(): HasMany
    {
        return $this->hasMany(MoneyDelivery::class);
    }
}
