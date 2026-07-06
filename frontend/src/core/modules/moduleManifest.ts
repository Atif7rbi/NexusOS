export type ModuleCategory =
  | "core"
  | "business"
  | "industry"
  | "ai"
  | "system";

export type ModuleRoute = {
  id: string;
  path: string;
  title: string;
};

export type ModuleManifest = {
  id: string;
  name: string;
  version: string;
  description: string;
  icon: string;
  category: ModuleCategory;
  defaultRoute: string;
  requiredCapability: string;
  requiredPermission: string;
  routes: ModuleRoute[];
  enabled: boolean;
};
