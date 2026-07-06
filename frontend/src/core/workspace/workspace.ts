export type WorkspaceWidget = {
  id: string;
  order: number;
  visible: boolean;
};

export type WorkspaceDefinition = {
  id: string;
  name: string;
  description: string;
  defaultRoute: string;
  widgets: WorkspaceWidget[];
};
