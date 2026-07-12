<?php

declare(strict_types=1);

namespace App\Services\TenantLicense;

use App\Data\TenantLicense\IssueTrialLicenseResult;
use App\Exceptions\TenantLicense\CurrentTenantLicenseAlreadyExistsException;
use App\Exceptions\TenantLicense\PlanNotAvailableForLicenseException;
use App\Exceptions\TenantLicense\TrialAlreadyConsumedException;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Services\TenantLicense\Resolvers\TrialPeriodResolver;
use App\Services\TenantModule\Operations\SyncTenantModulesFromPlanOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues the first and only operational trial license for a Tenant.
 *
 * This service owns:
 * - the transaction;
 * - Tenant aggregate locking;
 * - current-license validation;
 * - historical trial-consumption validation;
 * - trial-license creation;
 * - cross-domain module synchronization;
 * - TenantLicense audit ownership;
 * - the result DTO.
 */
final class IssueTrialLicense
{
    public function __construct(
        private readonly TrialPeriodResolver $trialPeriodResolver,
        private readonly SyncTenantModulesFromPlanOperation $syncOperation,
    ) {
    }

    public function handle(
        string $tenantId,
        string $planId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): IssueTrialLicenseResult {
        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $planId,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): IssueTrialLicenseResult {
            /*
             * Official aggregate lock order begins with Tenant.
             * This serializes license creation attempts for one Tenant.
             */
            Tenant::query()
                ->whereKey($tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $hasCurrentLicense = TenantLicense::query()
                ->where('tenant_id', $tenantId)
                ->current()
                ->exists();

            if ($hasCurrentLicense) {
                throw new CurrentTenantLicenseAlreadyExistsException(
                    'Tenant already has a current license.'
                );
            }

            $hasConsumedTrial = TenantLicense::query()
                ->where('tenant_id', $tenantId)
                ->where(
                    'license_origin',
                    TenantLicense::ORIGIN_TRIAL
                )
                ->exists();

            if ($hasConsumedTrial) {
                throw new TrialAlreadyConsumedException(
                    'Tenant has already consumed its trial license.'
                );
            }

            $plan = Plan::query()
                ->whereKey($planId)
                ->first();

            if ($plan === null || ! $plan->is_active) {
                throw new PlanNotAvailableForLicenseException(
                    'The selected plan is not available for a new trial license.'
                );
            }

            $expiresAt = $this->trialPeriodResolver->resolve(
                $plan,
                $occurredAt,
            );

            $license = new TenantLicense();

            $license->forceFill([
                'tenant_id' => $tenantId,
                'plan_id' => $plan->id,
                'license_origin' => TenantLicense::ORIGIN_TRIAL,
                'status' => TenantLicense::STATUS_TRIAL,
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
                'event' => 'tenant_license.trial_issued',
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
                    'trial_days' => config(
                        'nexusos.tenant_license.trial_days'
                    ),
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

            return new IssueTrialLicenseResult(
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
