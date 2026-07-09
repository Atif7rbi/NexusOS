<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function tenantModules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDeprecated(Builder $query): Builder
    {
        return $query->whereNotNull('deprecated_at');
    }

    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
