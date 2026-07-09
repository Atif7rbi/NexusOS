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
 * RBAC permission attached to a module.
 *
 * @property string $id
 * @property string $module_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $deprecated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Permission extends Model
{
    use HasUlids;

    protected $table = 'permissions';

    protected $fillable = [
        'module_id',
        'code',
        'name',
        'description',
        'is_active',
        'deprecated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'deprecated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Module, self>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * @return HasMany<RolePermission>
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForModule(Builder $query, string $moduleId): Builder
    {
        return $query->where('module_id', $moduleId);
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
