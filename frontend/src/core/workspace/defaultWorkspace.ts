import type { WorkspaceDefinition } from "./workspace";

export const defaultWorkspace: WorkspaceDefinition = {
  id: "executive",
  name: "Executive Workspace",
  description: "Default executive workspace.",
  defaultRoute: "/dashboard",
  widgets: [
    {
      id: "revenue",
      order: 1,
      visible: true,
    },
    {
      id: "projects",
      order: 2,
      visible: true,
    },
    {
      id: "alerts",
      order: 3,
      visible: true,
    },
  ],
};
