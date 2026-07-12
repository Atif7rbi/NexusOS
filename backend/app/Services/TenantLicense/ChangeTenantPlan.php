<?php

declare(strict_types=1);

namespace App\Services\TenantLicense;

use App\Data\TenantLicense\ChangeTenantPlanResult;
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
 * Changes the Plan assigned to an active TenantLicense.
 *
 * Supported transitions:
 *
 * - finite -> finite
 * - finite -> lifetime
 * - lifetime -> different lifetime
 *
 * Unsupported in v1:
 *
 * - lifetime -> finite
 * - changing a non-active license
 * - assigning the same Plan
 * - adopting an inactive target Plan
 *
 * When the target Plan has the same billing unit and count, the existing
 * license period is preserved. Otherwise, a new period starts at occurredAt.
 */
final class ChangeTenantPlan
{
    public function __construct(
        private readonly TenantLicenseTransitionRules $transitionRules,
        private readonly SubscriptionPeriodResolver $periodResolver,
        private readonly SyncTenantModulesFromPlanOperation $syncOperation,
    ) {
    }

    public function handle(
        string $tenantId,
        string $tenantLicenseId,
        string $newPlanId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): ChangeTenantPlanResult {
        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $tenantLicenseId,
            $newPlanId,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): ChangeTenantPlanResult {
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
                ->whereKey($tenantLicenseId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $currentPlan = Plan::query()
                ->whereKey($license->plan_id)
                ->firstOrFail();

            $newPlan = Plan::query()
                ->whereKey($newPlanId)
                ->firstOrFail();

            $this->transitionRules->assertPeriodConsistency(
                $license,
                $currentPlan,
            );

            $this->transitionRules->assertLicensePlanCanBeChanged(
                $license,
                $currentPlan,
                $newPlan,
            );

            $periodRestarted = ! $this->hasSameBillingPeriod(
                $currentPlan,
                $newPlan,
            );

            $before = [
                'license_origin' => $license->license_origin,
                'status' => $license->status,
                'plan_id' => $license->plan_id,
                'starts_at' => $license->starts_at->toISOString(),
                'expires_at' => $license
                    ->expires_at
                    ?->toISOString(),
                'grace_ends_at' => $license
                    ->grace_ends_at
                    ?->toISOString(),
            ];

            $newStartsAt = $periodRestarted
                ? $occurredAt
                : $license->starts_at;

            $newExpiresAt = $periodRestarted
                ? $this->periodResolver->resolve(
                    $newPlan,
                    $occurredAt,
                )
                : $license->expires_at;

            $newGraceEndsAt = $periodRestarted
                ? null
                : $license->grace_ends_at;

            $license->forceFill([
                'plan_id' => $newPlan->id,
                'starts_at' => $newStartsAt,
                'expires_at' => $newExpiresAt,
                'grace_ends_at' => $newGraceEndsAt,
                'updated_at' => $occurredAt,
            ]);

            $license->save();

            $syncResult = $this->syncOperation->execute(
                tenantId: $tenantId,
                licenseId: $license->id,
                planId: $newPlan->id,
                occurredAt: $occurredAt,
                actorUserId: $actorUserId,
                requestId: $resolvedRequestId,
            );

            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'category' => AuditLog::CATEGORY_BUSINESS,
                'event' => 'tenant_license.plan_changed',
                'entity_type' => TenantLicense::class,
                'entity_id' => $license->id,
                'request_id' => $resolvedRequestId,
                'changes' => [
                    'before' => $before,
                    'after' => [
                        'license_origin' => $license->license_origin,
                        'status' => $license->status,
                        'plan_id' => $license->plan_id,
                        'starts_at' => $license
                            ->starts_at
                            ->toISOString(),
                        'expires_at' => $license
                            ->expires_at
                            ?->toISOString(),
                        'grace_ends_at' => $license
                            ->grace_ends_at
                            ?->toISOString(),
                    ],
                ],
                'snapshot' => [
                    'tenant_license_id' => $license->id,
                    'tenant_id' => $license->tenant_id,
                    'previous_plan_id' => $currentPlan->id,
                    'new_plan_id' => $newPlan->id,
                    'license_origin' => $license->license_origin,
                    'status' => $license->status,
                ],
                'metadata' => [
                    'previous_plan_id' => $currentPlan->id,
                    'new_plan_id' => $newPlan->id,
                    'period_restarted' => $periodRestarted,
                    'previous_billing_period' => [
                        'unit' => $currentPlan->billing_period_unit,
                        'count' => $currentPlan->billing_period_count,
                    ],
                    'new_billing_period' => [
                        'unit' => $newPlan->billing_period_unit,
                        'count' => $newPlan->billing_period_count,
                    ],
                    'module_sync' => [
                        'created' => $syncResult->created,
                        'enabled' => $syncResult->enabled,
                        'disabled' => $syncResult->disabled,
                        'skipped_protected' => $syncResult
                            ->skippedProtected,
                        'unchanged' => $syncResult->unchanged,
                    ],
                ],
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => $occurredAt,
            ]);

            return new ChangeTenantPlanResult(
                tenantLicenseId: $license->id,
                tenantId: $license->tenant_id,
                previousPlanId: $currentPlan->id,
                newPlanId: $newPlan->id,
                status: $license->status,
                startsAt: $license->starts_at,
                expiresAt: $license->expires_at,
                graceEndsAt: $license->grace_ends_at,
                periodRestarted: $periodRestarted,
            );
        });
    }

    private function hasSameBillingPeriod(
        Plan $currentPlan,
        Plan $newPlan,
    ): bool {
        return $currentPlan->billing_period_unit
                === $newPlan->billing_period_unit
            && $currentPlan->billing_period_count
                === $newPlan->billing_period_count;
    }
}
