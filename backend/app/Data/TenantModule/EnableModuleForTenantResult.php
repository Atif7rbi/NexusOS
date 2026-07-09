<?php

declare(strict_types=1);

namespace App\Data\TenantModule;

final readonly class EnableModuleForTenantResult
{
    public function __construct(
        public string $tenantModuleId,
        public string $tenantId,
        public string $moduleId,
        public string $status,
        public string $source,
        public bool $changed,
    ) {}
}
