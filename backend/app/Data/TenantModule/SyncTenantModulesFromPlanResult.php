<?php

declare(strict_types=1);

namespace App\Data\TenantModule;

final readonly class SyncTenantModulesFromPlanResult
{
    /**
     * @param array<int, string> $createdModuleIds
     * @param array<int, string> $enabledModuleIds
     * @param array<int, string> $disabledModuleIds
     * @param array<int, string> $skippedProtectedModuleIds
     * @param array<int, string> $unchangedModuleIds
     */
    public function __construct(
        public string $tenantId,
        public string $planId,
        public int $created,
        public int $enabled,
        public int $disabled,
        public int $skippedProtected,
        public int $unchanged,
        public array $createdModuleIds,
        public array $enabledModuleIds,
        public array $disabledModuleIds,
        public array $skippedProtectedModuleIds,
        public array $unchangedModuleIds,
    ) {}
}
