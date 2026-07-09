<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModule extends Model
{
    use HasUlids;

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public const SOURCE_PLAN = 'plan';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_TRIAL = 'trial';
    public const SOURCE_PROMO = 'promo';
    public const SOURCE_OVERRIDE = 'override';

    public const PLAN_SYNC_PROTECTED_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_TRIAL,
        self::SOURCE_PROMO,
        self::SOURCE_OVERRIDE,
    ];

    protected $table = 'tenant_modules';

    protected $fillable = [
        'tenant_id',
        'module_id',
        'status',
        'source',
        'enabled_by',
        'enabled_at',
        'disabled_at',
    ];

    protected $casts = [
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DISABLED);
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForModule(Builder $query, string $moduleId): Builder
    {
        return $query->where('module_id', $moduleId);
    }

    public function scopeFromPlan(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_PLAN);
    }

    public function scopeManual(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_MANUAL);
    }

    public function scopeTrial(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_TRIAL);
    }

    public function scopePromo(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_PROMO);
    }

    public function scopeOverride(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_OVERRIDE);
    }

    public function scopeProtectedFromPlanSync(Builder $query): Builder
    {
        return $query->whereIn('source', self::PLAN_SYNC_PROTECTED_SOURCES);
    }
}
