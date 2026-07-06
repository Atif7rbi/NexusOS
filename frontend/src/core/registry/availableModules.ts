import { currentLicense, hasCapability } from "@/core/license/currentLicense";
import { moduleRegistry } from "@/core/registry/moduleRegistry";
import { hasPermission } from "@/core/permissions/currentUserPermissions";

/**
 * Returns modules available to the current customer and user.
 */
export function getAvailableModules() {
  return moduleRegistry.filter((module) => {
    return (
      hasCapability(module.requiredCapability) &&
      hasPermission(module.requiredPermission)
    );
  });
}

/**
 * Returns a short license summary for display.
 */
export function getLicenseSummary() {
  return {
    plan: currentLicense.plan,
    maxUsers: currentLicense.maxUsers,
    expiresAt: currentLicense.expiresAt,
  };
}
