<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Commercial subscription plan.
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $billing_cycle
 * @property string|null $description
 * @property string $price
 * @property string $currency
 * @property int|null $max_users
 * @property int|null $max_storage_mb
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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

    /**
     * @return HasMany<PlanModule>
     */
    public function planModules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }

    /**
     * @return HasMany<TenantLicense>
     */
    public function tenantLicenses(): HasMany
    {
        return $this->hasMany(TenantLicense::class);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('billing_cycle', self::BILLING_MONTHLY);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeYearly(Builder $query): Builder
    {
        return $query->where('billing_cycle', self::BILLING_YEARLY);
    }
}
