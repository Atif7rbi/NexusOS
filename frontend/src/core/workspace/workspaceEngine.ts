import { defaultWorkspace } from "./defaultWorkspace";

/**
 * Returns the active workspace.
 *
 * In future versions this will load user-specific layouts
 * from the backend.
 */
export function getCurrentWorkspace() {
  return defaultWorkspace;
}
