export type NavigationItem = {
  id: string;
  title: string;
  path: string;
  icon: string;
  badge?: string;
  permission?: string;
  module?: string;
};

export const mainNavigation: NavigationItem[] = [
  {
    id: "executive-dashboard",
    title: "Executive Dashboard",
    path: "/dashboard",
    icon: "📊",
    module: "core",
  },
  {
    id: "projects",
    title: "Projects",
    path: "/projects",
    icon: "🏗️",
    module: "projects",
  },
  {
    id: "tasks",
    title: "Tasks",
    path: "/tasks",
    icon: "✅",
    badge: "12",
    module: "tasks",
  },
  {
    id: "sales",
    title: "Sales",
    path: "/sales",
    icon: "💼",
    module: "sales",
  },
  {
    id: "crm",
    title: "CRM",
    path: "/crm",
    icon: "👥",
    module: "crm",
  },
  {
    id: "marketing",
    title: "Marketing",
    path: "/marketing",
    icon: "📣",
    module: "marketing",
  },
  {
    id: "finance",
    title: "Finance",
    path: "/finance",
    icon: "💰",
    module: "finance",
  },
  {
    id: "hr",
    title: "HR",
    path: "/hr",
    icon: "🧑‍💼",
    module: "hr",
  },
  {
    id: "documents",
    title: "Documents",
    path: "/documents",
    icon: "📁",
    module: "documents",
  },
  {
    id: "reports",
    title: "Reports",
    path: "/reports",
    icon: "📄",
    module: "reports",
  },
  {
    id: "settings",
    title: "Settings",
    path: "/settings",
    icon: "⚙️",
    module: "core",
  },
];
