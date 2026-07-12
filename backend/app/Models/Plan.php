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
 * @property string $billing_period_unit
 * @property int|null $billing_period_count
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

    public const BILLING_PERIOD_MONTH = 'month';
    public const BILLING_PERIOD_YEAR = 'year';
    public const BILLING_PERIOD_LIFETIME = 'lifetime';

    /**
     * @var list<int>
     */
    public const ALLOWED_MONTH_COUNTS = [
        1,
        3,
        6,
        10,
    ];

    protected $table = 'plans';

    protected $fillable = [
        'name',
        'code',
        'billing_period_unit',
        'billing_period_count',
        'description',
        'price',
        'currency',
        'max_users',
        'max_storage_mb',
        'is_active',
    ];

    protected $casts = [
        'billing_period_count' => 'integer',
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
        return $query->where(
            'billing_period_unit',
            self::BILLING_PERIOD_MONTH
        );
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeYearly(Builder $query): Builder
    {
        return $query->where(
            'billing_period_unit',
            self::BILLING_PERIOD_YEAR
        );
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeLifetime(Builder $query): Builder
    {
        return $query->where(
            'billing_period_unit',
            self::BILLING_PERIOD_LIFETIME
        );
    }

    public function isMonthly(): bool
    {
        return $this->billing_period_unit === self::BILLING_PERIOD_MONTH;
    }

    public function isYearly(): bool
    {
        return $this->billing_period_unit === self::BILLING_PERIOD_YEAR;
    }

    public function isLifetime(): bool
    {
        return $this->billing_period_unit === self::BILLING_PERIOD_LIFETIME;
    }
}
