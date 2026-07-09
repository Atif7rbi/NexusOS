<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * RBAC role model for global templates and tenant-specific roles.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $source_role_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property bool $is_template
 * @property bool $is_active
 * @property Carbon|null $deprecated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends Model
{
    use HasUlids;

    protected $table = 'roles';

    protected $fillable = [
        'tenant_id',
        'source_role_id',
        'code',
        'name',
        'description',
        'is_system',
        'is_template',
        'is_active',
        'deprecated_at',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_template' => 'boolean',
        'is_active' => 'boolean',
        'deprecated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, self>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Role, self>
     */
    public function sourceRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'source_role_id');
    }

    /**
     * @return HasMany<Role>
     */
    public function clonedRoles(): HasMany
    {
        return $this->hasMany(Role::class, 'source_role_id');
    }

    /**
     * @return HasMany<RolePermission>
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * @return HasMany<TenantUserRole>
     */
    public function tenantUserRoles(): HasMany
    {
        return $this->hasMany(TenantUserRole::class);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeTemplates(Builder $query): Builder
    {
        return $query->whereNull('tenant_id')->where('is_template', true);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeTenantRoles(Builder $query): Builder
    {
        return $query->whereNotNull('tenant_id')->where('is_template', false);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
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
    public function scopeDeprecated(Builder $query): Builder
    {
        return $query->whereNotNull('deprecated_at');
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }
}
