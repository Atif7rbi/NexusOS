<?php

declare(strict_types=1);

namespace App\Services\TenantLicense;

use App\Data\TenantLicense\ExpireTenantLicenseResult;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Services\TenantLicense\Policies\TenantLicenseTransitionRules;
use App\Services\TenantModule\Operations\RevokePlanModulesFromTenantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Expires an eligible TenantLicense.
 *
 * Owned transitions:
 *
 * - trial -> expired
 * - grace_period -> expired
 * - expired -> expired as an idempotent no-op
 *
 * Active licenses must enter grace period first.
 * Cancelled licenses cannot be expired.
 *
 * The idempotent path returns the current snapshot only. It does not
 * repair Tenant status, revoke modules, mutate data, or write audits.
 */
final class ExpireTenantLicense
{
    public function __construct(
        private readonly TenantLicenseTransitionRules $transitionRules,
        private readonly RevokePlanModulesFromTenantOperation $revokeOperation,
    ) {
    }

    public function handle(
        string $tenantId,
        string $tenantLicenseId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): ExpireTenantLicenseResult {
        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $tenantLicenseId,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): ExpireTenantLicenseResult {
            /*
             * Official aggregate lock order:
             *
             * Tenant
             *   ↓
             * TenantLicense
             *   ↓
             * TenantModule rows inside the revocation operation
             */
            $tenant = Tenant::query()
                ->whereKey($tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Do not use scopeCurrent() here. The service intentionally
             * supports retrying an already expired license by its ID.
             */
            $license = TenantLicense::query()
                ->whereKey($tenantLicenseId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $plan = Plan::query()
                ->whereKey($license->plan_id)
                ->firstOrFail();

            $this->transitionRules->assertPeriodConsistency(
                $license,
                $plan,
            );

            $this->transitionRules->assertLicenseCanExpire(
                $license,
                $plan,
                $occurredAt,
            );

            /*
             * Retry-safe final-state match.
             *
             * No reconciliation is performed here, even if historical
             * side effects such as Tenant suspension are missing.
             */
            if ($license->isExpired()) {
                return $this->result(
                    license: $license,
                    changed: false,
                );
            }

            $licenseBefore = [
                'license_origin' => $license->license_origin,
                'status' => $license->status,
                'plan_id' => $license->plan_id,
                'starts_at' => $license->starts_at->toISOString(),
                'expires_at' => $license->expires_at->toISOString(),
                'grace_ends_at' => $license
                    ->grace_ends_at
                    ?->toISOString(),
            ];

            $license->forceFill([
                'status' => TenantLicense::STATUS_EXPIRED,
                'updated_at' => $occurredAt,
            ]);

            $license->save();

            /*
             * Suspension is conditional on an actual Tenant state change.
             * Existing suspended/cancelled Tenants are not rewritten and
             * do not receive a synthetic suspension audit.
             */
            if ($tenant->status === Tenant::STATUS_ACTIVE) {
                $tenantBefore = [
                    'status' => $tenant->status,
                ];

                $tenant->forceFill([
                    'status' => Tenant::STATUS_SUSPENDED,
                    'updated_at' => $occurredAt,
                ]);

                $tenant->save();

                AuditLog::query()->create([
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $actorUserId,
                    'category' => AuditLog::CATEGORY_BUSINESS,
                    'event' => 'tenant.suspended_due_to_license_expiration',
                    'entity_type' => Tenant::class,
                    'entity_id' => $tenant->id,
                    'request_id' => $resolvedRequestId,
                    'changes' => [
                        'before' => $tenantBefore,
                        'after' => [
                            'status' => $tenant->status,
                        ],
                    ],
                    'snapshot' => [
                        'tenant_id' => $tenant->id,
                        'status' => $tenant->status,
                    ],
                    'metadata' => [
                        'license_id' => $license->id,
                        'plan_id' => $plan->id,
                    ],
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => $occurredAt,
                ]);
            }

            $revokeResult = $this->revokeOperation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                trigger: RevokePlanModulesFromTenantOperation::
                    TRIGGER_LICENSE_EXPIRATION,
                occurredAt: $occurredAt,
                actorUserId: $actorUserId,
                requestId: $resolvedRequestId,
            );

            AuditLog::query()->create([
                'tenant_id' => $tenant->id,
                'actor_user_id' => $actorUserId,
                'category' => AuditLog::CATEGORY_BUSINESS,
                'event' => 'tenant_license.expired',
                'entity_type' => TenantLicense::class,
                'entity_id' => $license->id,
                'request_id' => $resolvedRequestId,
                'changes' => [
                    'before' => $licenseBefore,
                    'after' => [
                        'license_origin' => $license->license_origin,
                        'status' => $license->status,
                        'plan_id' => $license->plan_id,
                        'starts_at' => $license
                            ->starts_at
                            ->toISOString(),
                        'expires_at' => $license
                            ->expires_at
                            ->toISOString(),
                        'grace_ends_at' => $license
                            ->grace_ends_at
                            ?->toISOString(),
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
                    'previous_status' => $licenseBefore['status'],
                    'expiration_trigger' =>
                        $licenseBefore['status']
                            === TenantLicense::STATUS_TRIAL
                                ? 'trial_expiry'
                                : 'grace_period_expiry',
                    'module_revocation' => [
                        'revoked' => $revokeResult->revoked,
                        'already_disabled' => $revokeResult
                            ->alreadyDisabled,
                    ],
                ],
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => $occurredAt,
            ]);

            return $this->result(
                license: $license,
                changed: true,
            );
        });
    }

    private function result(
        TenantLicense $license,
        bool $changed,
    ): ExpireTenantLicenseResult {
        /*
         * assertPeriodConsistency() guarantees expires_at is non-null
         * for trial, grace_period, and expired licenses.
         */
        if ($license->expires_at === null) {
            throw new \LogicException(
                'An expirable tenant license must have an expiry.'
            );
        }

        return new ExpireTenantLicenseResult(
            tenantLicenseId: $license->id,
            tenantId: $license->tenant_id,
            planId: $license->plan_id,
            status: $license->status,
            startsAt: $license->starts_at,
            expiresAt: $license->expires_at,
            graceEndsAt: $license->grace_ends_at,
            changed: $changed,
        );
    }
}
