<?php

declare(strict_types=1);

namespace App\Services\TenantLicense;

use App\Data\TenantLicense\CancelTenantLicenseResult;
use App\Exceptions\TenantLicense\InvalidCancellationReasonException;
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
 * Cancels a current TenantLicense immediately by an explicit commercial
 * or administrative decision.
 *
 * Owned transitions:
 *
 * - trial -> cancelled
 * - active -> cancelled
 * - grace_period -> cancelled
 *
 * Cancellation is immediate and has no temporal eligibility condition.
 * Expired and already-cancelled licenses are rejected.
 */
final class CancelTenantLicense
{
    private const MAX_REASON_LENGTH = 1000;

    public function __construct(
        private readonly TenantLicenseTransitionRules $transitionRules,
        private readonly RevokePlanModulesFromTenantOperation $revokeOperation,
    ) {
    }

    public function handle(
        string $tenantId,
        string $tenantLicenseId,
        string $reason,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): CancelTenantLicenseResult {
        /*
         * Text validation requires no database state or aggregate lock,
         * so invalid input is rejected before opening a transaction.
         */
        $normalizedReason = trim($reason);

        if ($normalizedReason === '') {
            throw InvalidCancellationReasonException::emptyReason();
        }

        if (mb_strlen($normalizedReason) > self::MAX_REASON_LENGTH) {
            throw InvalidCancellationReasonException::reasonTooLong(
                self::MAX_REASON_LENGTH,
            );
        }

        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $tenantLicenseId,
            $normalizedReason,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): CancelTenantLicenseResult {
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

            $this->transitionRules->assertLicenseCanBeCancelled(
                $license,
                $plan,
            );

            $licenseBefore = [
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

            $license->forceFill([
                'status' => TenantLicense::STATUS_CANCELLED,
                'updated_at' => $occurredAt,
            ]);

            $license->save();

            /*
             * Cancellation suspends only an operationally active Tenant.
             * Existing suspended/cancelled states are preserved and do not
             * receive a synthetic state-change AuditLog row.
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
                    'event' => 'tenant.suspended_due_to_license_cancellation',
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
                    TRIGGER_LICENSE_CANCELLATION,
                occurredAt: $occurredAt,
                actorUserId: $actorUserId,
                requestId: $resolvedRequestId,
            );

            AuditLog::query()->create([
                'tenant_id' => $tenant->id,
                'actor_user_id' => $actorUserId,
                'category' => AuditLog::CATEGORY_BUSINESS,
                'event' => 'tenant_license.cancelled',
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
                            ?->toISOString(),
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
                    'reason' => $normalizedReason,
                    'previous_status' => $licenseBefore['status'],
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

            return new CancelTenantLicenseResult(
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
