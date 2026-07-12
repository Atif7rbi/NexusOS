<?php

declare(strict_types=1);

namespace App\Services\TenantLicense;

use App\Data\TenantLicense\EnterLicenseGracePeriodResult;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Services\TenantLicense\Policies\TenantLicenseTransitionRules;
use App\Services\TenantLicense\Resolvers\GracePeriodResolver;
use App\Services\TenantModule\Operations\SyncTenantModulesFromPlanOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves an eligible finite active TenantLicense into grace period.
 *
 * Owns exactly one lifecycle transition:
 *
 * active -> grace_period
 *
 * Grace is anchored on the original expires_at, never occurredAt.
 * Scheduler delay must not grant additional entitlement time.
 */
final class EnterLicenseGracePeriod
{
    public function __construct(
        private readonly TenantLicenseTransitionRules $transitionRules,
        private readonly GracePeriodResolver $gracePeriodResolver,
        private readonly SyncTenantModulesFromPlanOperation $syncOperation,
    ) {
    }

    public function handle(
        string $tenantId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): EnterLicenseGracePeriodResult {
        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): EnterLicenseGracePeriodResult {
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

            $this->transitionRules->assertLicenseCanEnterGracePeriod(
                $license,
                $plan,
                $occurredAt,
            );

            /*
             * assertPeriodConsistency() guarantees that a finite active
             * license has a non-null expires_at.
             */
            $expiresAt = $license->expires_at;

            if ($expiresAt === null) {
                throw new \LogicException(
                    'A grace-eligible tenant license must have an expiry.'
                );
            }

            $before = [
                'license_origin' => $license->license_origin,
                'status' => $license->status,
                'plan_id' => $license->plan_id,
                'starts_at' => $license->starts_at->toISOString(),
                'expires_at' => $expiresAt->toISOString(),
                'grace_ends_at' => $license->grace_ends_at?->toISOString(),
            ];

            /*
             * The grace period is anchored on the contractual expiry,
             * not on delayed discovery time.
             */
            $graceEndsAt = $this->gracePeriodResolver->resolve(
                $plan,
                $expiresAt,
            );

            $license->forceFill([
                'status' => TenantLicense::STATUS_GRACE_PERIOD,
                'grace_ends_at' => $graceEndsAt,
                'updated_at' => $occurredAt,
            ]);

            $license->save();

            /*
             * Grace remains operational. Synchronization is always run
             * to reassert current Plan entitlements and repair drift.
             */
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
                'event' => 'tenant_license.grace_period_entered',
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
                        'grace_ends_at' => $license
                            ->grace_ends_at
                            ->toISOString(),
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
                    'grace_anchor' => $expiresAt->toISOString(),
                    'grace_period_days' => config(
                        'nexusos.tenant_license.grace_period_days'
                    ),
                    'discovered_at' => $occurredAt->toISOString(),
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

            return new EnterLicenseGracePeriodResult(
                tenantLicenseId: $license->id,
                tenantId: $license->tenant_id,
                planId: $license->plan_id,
                status: $license->status,
                startsAt: $license->starts_at,
                expiresAt: $license->expires_at,
                graceEndsAt: $license->grace_ends_at,
            );
        });
    }
}
