import type { ModuleManifest } from "@/core/modules/moduleManifest";

/**
 * Defines the Executive Dashboard module.
 */
export const dashboardModule: ModuleManifest = {
  id: "dashboard",
  name: "Executive Dashboard",
  version: "0.1.0",
  description: "Executive workspace, KPIs, alerts, and business overview.",
  icon: "📊",
  category: "business",
  defaultRoute: "/dashboard",
  requiredCapability: "dashboard.access",
  requiredPermission: "dashboard.view",
  enabled: true,
  routes: [
    {
      id: "dashboard.home",
      path: "/dashboard",
      title: "Executive Dashboard",
    },
  ],
};
