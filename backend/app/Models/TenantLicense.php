<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commercial license assigned to a tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property string $license_origin
 * @property string $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $grace_ends_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TenantLicense extends Model
{
    use HasUlids;

    public const ORIGIN_TRIAL = 'trial';
    public const ORIGIN_SUBSCRIPTION = 'subscription';

    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_GRACE_PERIOD = 'grace_period';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses protected by the one-current-license database index.
     *
     * @var list<string>
     */
    public const CURRENT_STATUSES = [
        self::STATUS_TRIAL,
        self::STATUS_ACTIVE,
        self::STATUS_GRACE_PERIOD,
    ];

    /**
     * Terminal historical statuses.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'tenant_licenses';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'license_origin',
        'status',
        'starts_at',
        'expires_at',
        'grace_ends_at',
    ];

    protected $casts = [
        'starts_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'grace_ends_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForTenant(
        Builder $query,
        string $tenantId
    ): Builder {
        return $query->where('tenant_id', $tenantId);
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
    public function scopeTrial(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_TRIAL);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeGracePeriod(Builder $query): Builder
    {
        return $query->where(
            'status',
            self::STATUS_GRACE_PERIOD
        );
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where(
            'status',
            self::STATUS_CANCELLED
        );
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            self::CURRENT_STATUSES
        );
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            self::TERMINAL_STATUSES
        );
    }

    public function originatedFromTrial(): bool
    {
        return $this->license_origin === self::ORIGIN_TRIAL;
    }

    public function originatedFromSubscription(): bool
    {
        return $this->license_origin === self::ORIGIN_SUBSCRIPTION;
    }

    public function isCurrent(): bool
    {
        return in_array(
            $this->status,
            self::CURRENT_STATUSES,
            true
        );
    }

    public function isTerminal(): bool
    {
        return in_array(
            $this->status,
            self::TERMINAL_STATUSES,
            true
        );
    }

    public function isTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === self::STATUS_GRACE_PERIOD;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
