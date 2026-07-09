<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Product module definition.
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $category
 * @property string $version
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $deprecated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Module extends Model
{
    use HasUlids;

    public const CATEGORY_CORE = 'core';
    public const CATEGORY_BUSINESS = 'business';
    public const CATEGORY_INDUSTRY = 'industry';
    public const CATEGORY_REPORTING = 'reporting';
    public const CATEGORY_AI = 'ai';
    public const CATEGORY_INTEGRATION = 'integration';

    protected $table = 'modules';

    protected $fillable = [
        'name',
        'code',
        'category',
        'version',
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
     * @return HasMany<TenantModule>
     */
    public function tenantModules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    /**
     * @return HasMany<PlanModule>
     */
    public function planModules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }

    /**
     * @return HasMany<Permission>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
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

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
