<?php

declare(strict_types=1);

namespace App\Services\TenantLicense;

use App\Data\TenantLicense\StartTenantSubscriptionResult;
use App\Exceptions\TenantLicense\CurrentTenantLicenseAlreadyExistsException;
use App\Exceptions\TenantLicense\PlanNotAvailableForLicenseException;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Services\TenantLicense\Resolvers\SubscriptionPeriodResolver;
use App\Services\TenantModule\Operations\SyncTenantModulesFromPlanOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a new active subscription for a Tenant that has no current license.
 *
 * Historical expired and cancelled licenses do not prevent a new
 * subscription. Trial, active, and grace-period licenses are current
 * licenses and therefore block creation.
 */
final class StartTenantSubscription
{
    public function __construct(
        private readonly SubscriptionPeriodResolver $periodResolver,
        private readonly SyncTenantModulesFromPlanOperation $syncOperation,
    ) {
    }

    public function handle(
        string $tenantId,
        string $planId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): StartTenantSubscriptionResult {
        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $planId,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): StartTenantSubscriptionResult {
            /*
             * Tenant is the concurrency aggregate for license creation.
             * All competing creation attempts for the same Tenant begin
             * by locking this row.
             */
            Tenant::query()
                ->whereKey($tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Historical expired/cancelled rows are intentionally allowed.
             * Only CURRENT_STATUSES block a new subscription.
             */
            $hasCurrentLicense = TenantLicense::query()
                ->where('tenant_id', $tenantId)
                ->current()
                ->exists();

            if ($hasCurrentLicense) {
                throw new CurrentTenantLicenseAlreadyExistsException(
                    'Tenant already has a current license.'
                );
            }

            $plan = Plan::query()
                ->whereKey($planId)
                ->first();

            if ($plan === null || ! $plan->is_active) {
                throw new PlanNotAvailableForLicenseException(
                    'The selected plan is not available for a new subscription.'
                );
            }

            $expiresAt = $this->periodResolver->resolve(
                $plan,
                $occurredAt,
            );

            $license = new TenantLicense();

            $license->forceFill([
                'tenant_id' => $tenantId,
                'plan_id' => $plan->id,
                'license_origin' => TenantLicense::ORIGIN_SUBSCRIPTION,
                'status' => TenantLicense::STATUS_ACTIVE,
                'starts_at' => $occurredAt,
                'expires_at' => $expiresAt,
                'grace_ends_at' => null,
                'created_at' => $occurredAt,
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
                'event' => 'tenant_license.subscription_started',
                'entity_type' => TenantLicense::class,
                'entity_id' => $license->id,
                'request_id' => $resolvedRequestId,
                'changes' => [
                    'before' => null,
                    'after' => [
                        'license_origin' => $license->license_origin,
                        'status' => $license->status,
                        'plan_id' => $license->plan_id,
                        'starts_at' => $license->starts_at->toISOString(),
                        'expires_at' => $license
                            ->expires_at
                            ?->toISOString(),
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
                    'billing_period' => [
                        'unit' => $plan->billing_period_unit,
                        'count' => $plan->billing_period_count,
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

            return new StartTenantSubscriptionResult(
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
