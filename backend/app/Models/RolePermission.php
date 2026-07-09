<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot-like model connecting roles to permissions.
 *
 * @property string $id
 * @property string $role_id
 * @property string $permission_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RolePermission extends Model
{
    use HasUlids;

    protected $table = 'role_permissions';

    protected $fillable = [
        'role_id',
        'permission_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Role, self>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Permission, self>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForRole(Builder $query, string $roleId): Builder
    {
        return $query->where('role_id', $roleId);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForPermission(Builder $query, string $permissionId): Builder
    {
        return $query->where('permission_id', $permissionId);
    }
}
