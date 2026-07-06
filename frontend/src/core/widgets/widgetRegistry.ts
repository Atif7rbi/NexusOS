import type { WidgetManifest } from "./widget";

/**
 * Global Widget Registry.
 *
 * Every dashboard widget must register itself here.
 */
export const widgetRegistry: WidgetManifest[] = [];
