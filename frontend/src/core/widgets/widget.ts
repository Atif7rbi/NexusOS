export type WidgetSize =
  | "small"
  | "medium"
  | "large"
  | "full";

export type WidgetManifest = {
  id: string;

  title: string;

  version: string;

  description: string;

  module: string;

  permission: string;

  capability: string;

  defaultSize: WidgetSize;

  enabled: boolean;
};
