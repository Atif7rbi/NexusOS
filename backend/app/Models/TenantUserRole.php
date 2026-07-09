<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped role assignment for a tenant user.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $tenant_user_id
 * @property string $role_id
 * @property string|null $assigned_by
 * @property Carbon $assigned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TenantUserRole extends Model
{
    use HasUlids;

    protected $table = 'tenant_user_roles';

    protected $fillable = [
        'tenant_id',
        'tenant_user_id',
        'role_id',
        'assigned_by',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
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
     * @return BelongsTo<TenantUser, self>
     */
    public function tenantUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class);
    }

    /**
     * @return BelongsTo<Role, self>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
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
    public function scopeForTenantUser(Builder $query, string $tenantUserId): Builder
    {
        return $query->where('tenant_user_id', $tenantUserId);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForRole(Builder $query, string $roleId): Builder
    {
        return $query->where('role_id', $roleId);
    }
}
