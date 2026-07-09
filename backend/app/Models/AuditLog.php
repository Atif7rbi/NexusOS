<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable audit ledger entry.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_user_id
 * @property string $category
 * @property string $event
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property string|null $request_id
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $snapshot
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUlids;

    public const CATEGORY_BUSINESS = 'business';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_SYSTEM = 'system';

    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'category',
        'event',
        'entity_type',
        'entity_id',
        'request_id',
        'changes',
        'snapshot',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'snapshot' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, self>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
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
    public function scopeForActor(Builder $query, string $userId): Builder
    {
        return $query->where('actor_user_id', $userId);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeBusiness(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_BUSINESS);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeSecurity(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_SECURITY);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_SYSTEM);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeByRequestId(Builder $query, string $requestId): Builder
    {
        return $query->where('request_id', $requestId);
    }
}
