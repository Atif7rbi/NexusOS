<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant organization account.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $default_currency
 * @property string $timezone
 * @property string $locale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tenant extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'tenants';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'default_currency',
        'timezone',
        'locale',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return HasMany<TenantUser>
     */
    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * @return HasMany<TenantModule>
     */
    public function tenantModules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    /**
     * @return HasMany<TenantLicense>
     */
    public function tenantLicenses(): HasMany
    {
        return $this->hasMany(TenantLicense::class);
    }

    /**
     * @return HasMany<Role>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<TenantUserRole>
     */
    public function tenantUserRoles(): HasMany
    {
        return $this->hasMany(TenantUserRole::class);
    }

    /**
     * @return HasMany<AuditLog>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }
}
