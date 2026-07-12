<?php

declare(strict_types=1);

namespace App\Services\TenantLicense;

use App\Data\TenantLicense\RenewTenantLicenseResult;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Services\TenantLicense\Policies\TenantLicenseTransitionRules;
use App\Services\TenantLicense\Resolvers\SubscriptionPeriodResolver;
use App\Services\TenantModule\Operations\SyncTenantModulesFromPlanOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Renews an existing finite TenantLicense.
 *
 * Owns two state-dependent branches of the same commercial use case:
 *
 * - active -> active:
 *   Early renewal anchored on the existing expires_at.
 *   starts_at is preserved.
 *
 * - grace_period -> active:
 *   Recovery renewal anchored on occurredAt.
 *   starts_at is restarted and grace_ends_at is cleared.
 *
 * Lifetime licenses cannot be renewed.
 */
final class RenewTenantLicense
{
    public const TYPE_EARLY = 'early';
    public const TYPE_RECOVERY = 'recovery';

    public function __construct(
        private readonly TenantLicenseTransitionRules $transitionRules,
        private readonly SubscriptionPeriodResolver $periodResolver,
        private readonly SyncTenantModulesFromPlanOperation $syncOperation,
    ) {
    }

    public function handle(
        string $tenantId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): RenewTenantLicenseResult {
        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): RenewTenantLicenseResult {
            /*
             * Official aggregate lock order:
             *
             * Tenant
             *   ↓
             * TenantLicense
             *   ↓
             * TenantModule rows inside the synchronization operation
             */
            Tenant::query()
                ->whereKey($tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $license = TenantLicense::query()
                ->where('tenant_id', $tenantId)
                ->current()
                ->orderBy('id')
                ->lockForUpdate()
                ->firstOrFail();

            $plan = Plan::query()
                ->whereKey($license->plan_id)
                ->firstOrFail();

            $this->transitionRules->assertPeriodConsistency(
                $license,
                $plan,
            );

            $this->transitionRules->assertLicenseCanBeRenewed(
                $license,
                $plan,
                $occurredAt,
            );

            $before = [
                'license_origin' => $license->license_origin,
                'status' => $license->status,
                'plan_id' => $license->plan_id,
                'starts_at' => $license->starts_at->toISOString(),
                'expires_at' => $license->expires_at?->toISOString(),
                'grace_ends_at' => $license->grace_ends_at?->toISOString(),
            ];

            $isRecovery = $license->isInGracePeriod();

            $renewalType = $isRecovery
                ? self::TYPE_RECOVERY
                : self::TYPE_EARLY;

            /*
             * Early renewal preserves all remaining paid time by extending
             * from the existing expiry.
             *
             * Grace recovery starts a new paid period from occurredAt.
             */
            $anchor = $isRecovery
                ? $occurredAt
                : $license->expires_at;

            $expiresAt = $this->periodResolver->resolve(
                $plan,
                $anchor,
            );

            /*
             * The transition policy rejects lifetime plans before this
             * point, so every successful renewal must resolve a finite end.
             */
            if ($expiresAt === null) {
                throw new \LogicException(
                    'A renewable tenant license must resolve a finite expiry.'
                );
            }

            $license->forceFill([
                'status' => TenantLicense::STATUS_ACTIVE,
                'starts_at' => $isRecovery
                    ? $occurredAt
                    : $license->starts_at,
                'expires_at' => $expiresAt,
                'grace_ends_at' => null,
                'updated_at' => $occurredAt,
            ]);

            $license->save();

            $syncResult = $this->syncOperation->execute(
                tenantId: $tenantId,
                licenseId: $license->id,
                planId: $plan->id,
                occurredAt: $occurredAt,
                actorUserId: $actorUserId,
                requestId: $resolvedRequestId,
            );

            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'category' => AuditLog::CATEGORY_BUSINESS,
                'event' => 'tenant_license.renewed',
                'entity_type' => TenantLicense::class,
                'entity_id' => $license->id,
                'request_id' => $resolvedRequestId,
                'changes' => [
                    'before' => $before,
                    'after' => [
                        'license_origin' => $license->license_origin,
                        'status' => $license->status,
                        'plan_id' => $license->plan_id,
                        'starts_at' => $license->starts_at->toISOString(),
                        'expires_at' => $license->expires_at->toISOString(),
                        'grace_ends_at' => null,
                    ],
                ],
                'snapshot' => [
                    'tenant_license_id' => $license->id,
                    'tenant_id' => $license->tenant_id,
                    'plan_id' => $license->plan_id,
                    'license_origin' => $license->license_origin,
                    'status' => $license->status,
                ],
                'metadata' => [
                    'renewal_type' => $renewalType,
                    'renewal_anchor' => $anchor->toISOString(),
                    'billing_period_unit' => $plan->billing_period_unit,
                    'billing_period_count' => $plan->billing_period_count,
                    'module_sync' => [
                        'created' => $syncResult->created,
                        'enabled' => $syncResult->enabled,
                        'disabled' => $syncResult->disabled,
                        'skipped_protected' => $syncResult->skippedProtected,
                        'unchanged' => $syncResult->unchanged,
                    ],
                ],
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => $occurredAt,
            ]);

            return new RenewTenantLicenseResult(
                tenantLicenseId: $license->id,
                tenantId: $license->tenant_id,
                planId: $license->plan_id,
                status: $license->status,
                startsAt: $license->starts_at,
                expiresAt: $license->expires_at,
                graceEndsAt: $license->grace_ends_at,
                renewalType: $renewalType,
            );
        });
    }
}
