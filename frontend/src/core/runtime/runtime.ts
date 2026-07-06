export type Tenant = {
  id: string;
  name: string;
  industry: string;
};

export type CompanyLicense = {
  plan: "starter" | "professional" | "enterprise";
  maxUsers: number;
  expiresAt: string;
};

export type RuntimeUser = {
  id: string;
  name: string;
  email: string;
  username: string;
  role: string;
  emailVerified: boolean;
};

export type UserWorkspace = {
  defaultRoute: string;
  language: "ar" | "en";
  density: "comfortable" | "compact";
};

/**
 * Represents the current NexusOS runtime context.
 */
export type NexusRuntime = {
  tenant: Tenant;
  license: CompanyLicense;
  user: RuntimeUser;
  permissions: string[];
  workspace: UserWorkspace;
};
