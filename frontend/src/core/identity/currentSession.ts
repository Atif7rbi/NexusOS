import type { IdentityProfile, IdentitySession } from "./identity";

export const currentSession: IdentitySession = {
  authenticated: true,
  state: "authenticated",
  lastLoginAt: "2026-07-06T12:00:00Z",
};

export const currentIdentity: IdentityProfile = {
  id: "user-001",
  username: "atif",
  email: "atif@example.com",
  emailVerified: true,
  role: "executive",
};
