<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUlids;

    public const BILLING_MONTHLY = 'monthly';
    public const BILLING_YEARLY = 'yearly';

    protected $table = 'plans';

    protected $fillable = [
        'name',
        'code',
        'billing_cycle',
        'description',
        'price',
        'currency',
        'max_users',
        'max_storage_mb',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'max_users' => 'integer',
        'max_storage_mb' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function planModules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }

    public function tenantLicenses(): HasMany
    {
        return $this->hasMany(TenantLicense::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }

    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('billing_cycle', self::BILLING_MONTHLY);
    }

    public function scopeYearly(Builder $query): Builder
    {
        return $query->where('billing_cycle', self::BILLING_YEARLY);
    }
}
