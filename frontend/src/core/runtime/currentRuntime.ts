import type { NexusRuntime } from "./runtime";

/**
 * Temporary runtime state used until backend authentication is connected.
 */
export const currentRuntime: NexusRuntime = {
  tenant: {
    id: "ufq",
    name: "UFQ Real Estate Development",
    industry: "Real Estate",
  },
  license: {
    plan: "enterprise",
    maxUsers: 250,
    expiresAt: "2030-12-31",
  },
  user: {
    id: "user-001",
    name: "Atif Alharbi",
    email: "atif@example.com",
    username: "atif",
    role: "executive",
    emailVerified: true,
  },
  permissions: [
    "dashboard.view",
    "crm_sales.view",
    "projects.view",
    "real_estate.view",
    "clients.view",
    "brokers.view",
    "contracts_payments.view",
    "finance.view",
    "hr.view",
    "reports_ai.view",
  ],
  workspace: {
    defaultRoute: "/dashboard",
    language: "en",
    density: "comfortable",
  },
};
