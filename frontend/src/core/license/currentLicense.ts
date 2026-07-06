export type LicensePlan = "starter" | "professional" | "enterprise";

export type LicenseConfig = {
  plan: LicensePlan;
  capabilities: Record<string, boolean>;
  maxUsers: number;
  expiresAt: string;
};

/**
 * Represents the active customer license during frontend development.
 */
export const currentLicense: LicenseConfig = {
  plan: "enterprise",
  maxUsers: 250,
  expiresAt: "2030-12-31",
  capabilities: {
    "dashboard.access": true,
    "crm_sales.access": true,
    "projects.access": true,
    "real_estate.access": true,
    "clients.access": true,
    "brokers.access": true,
    "contracts_payments.access": true,
    "finance.access": true,
    "hr.access": true,
    "reports_ai.access": true,
  },
};

/**
 * Returns whether the active license allows a capability.
 */
export function hasCapability(capability: string) {
  return currentLicense.capabilities[capability] === true;
}
