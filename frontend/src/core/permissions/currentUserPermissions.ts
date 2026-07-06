export type UserRole = "executive" | "project-manager" | "accountant" | "employee";

export type UserPermissionConfig = {
  role: UserRole;
  permissions: Record<string, boolean>;
};

/**
 * Represents current user permissions during frontend development.
 */
export const currentUserPermissions: UserPermissionConfig = {
  role: "executive",
  permissions: {
    "dashboard.view": true,
    "crm_sales.view": true,
    "projects.view": true,
    "real_estate.view": true,
    "clients.view": true,
    "brokers.view": true,
    "contracts_payments.view": true,
    "finance.view": true,
    "hr.view": true,
    "reports_ai.view": true,
  },
};

/**
 * Returns whether the current user has a permission.
 */
export function hasPermission(permission: string) {
  return currentUserPermissions.permissions[permission] === true;
}
