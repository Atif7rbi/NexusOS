import { getAvailableModules } from "@/core/registry/availableModules";
import type { NavigationItem } from "./navigation";

/**
 * Builds the application navigation from registered modules.
 */
export function buildNavigation(): NavigationItem[] {
  return getAvailableModules()
    .map((module, index) => ({
      id: module.id,
      title: module.title,
      icon: module.icon,
      path: module.path,
      order: index + 1,
    }))
    .sort((a, b) => a.order - b.order);
}
