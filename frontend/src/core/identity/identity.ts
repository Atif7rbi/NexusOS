export type AuthenticationState =
  | "anonymous"
  | "authenticated"
  | "locked"
  | "password_reset_required";

export type IdentitySession = {
  authenticated: boolean;
  state: AuthenticationState;
  lastLoginAt: string | null;
};

export type IdentityProfile = {
  id: string;
  username: string;
  email: string;
  emailVerified: boolean;
  role: string;
};
