<?php

declare(strict_types=1);

namespace App\Services\TenantLicense;

use App\Data\TenantLicense\ActivateTenantLicenseResult;
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
 * Activates an existing trial license as a paid subscription.
 *
 * Owns exactly one lifecycle transition:
 *
 * trial -> active
 *
 * The Plan is grandfathered: an existing valid trial may be activated
 * even when its assigned Plan is no longer available for new sales.
 */
final class ActivateTenantLicense
{
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
    ): ActivateTenantLicenseResult {
        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): ActivateTenantLicenseResult {
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

            $this->transitionRules->assertLicenseCanBeActivated(
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

            $expiresAt = $this->periodResolver->resolve(
                $plan,
                $occurredAt,
            );

            $license->forceFill([
                'status' => TenantLicense::STATUS_ACTIVE,
                'starts_at' => $occurredAt,
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
                'event' => 'tenant_license.activated',
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
                        'expires_at' => $license->expires_at?->toISOString(),
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
                    'billing_period_unit' => $plan->billing_period_unit,
                    'billing_period_count' => $plan->billing_period_count,
                    'plan_was_active' => $plan->is_active,
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

            return new ActivateTenantLicenseResult(
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
