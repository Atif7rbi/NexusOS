export type ModuleId =
  | "dashboard"
  | "crm-sales"
  | "projects"
  | "real-estate"
  | "clients"
  | "brokers"
  | "contracts-payments"
  | "finance"
  | "hr"
  | "reports-ai";

export type ModuleDefinition = {
  id: ModuleId;
  title: string;
  description: string;
  icon: string;
  path: string;
  requiredCapability: string;
  requiredPermission: string;
};

/**
 * Defines all registered NexusOS business modules.
 */
export const moduleRegistry: ModuleDefinition[] = [
  {
    id: "dashboard",
    title: "Dashboard",
    description: "Executive overview and business command center.",
    icon: "📊",
    path: "/dashboard",
    requiredCapability: "dashboard.access",
    requiredPermission: "dashboard.view",
  },
  {
    id: "crm-sales",
    title: "CRM & Sales",
    description: "Leads, opportunities, deals, quotations, and sales funnel.",
    icon: "💼",
    path: "/crm-sales",
    requiredCapability: "crm_sales.access",
    requiredPermission: "crm_sales.view",
  },
  {
    id: "projects",
    title: "Projects",
    description: "Projects, phases, teams, budgets, risks, and progress.",
    icon: "🏗️",
    path: "/projects",
    requiredCapability: "projects.access",
    requiredPermission: "projects.view",
  },
  {
    id: "real-estate",
    title: "Real Estate Units",
    description: "Units, reservations, inventory, and real estate operations.",
    icon: "🏢",
    path: "/real-estate",
    requiredCapability: "real_estate.access",
    requiredPermission: "real_estate.view",
  },
  {
    id: "clients",
    title: "Clients",
    description: "Client profiles, communication history, and contracts.",
    icon: "👥",
    path: "/clients",
    requiredCapability: "clients.access",
    requiredPermission: "clients.view",
  },
  {
    id: "brokers",
    title: "Marketing & Brokers",
    description: "Campaigns, brokers, marketers, commissions, and leads.",
    icon: "📣",
    path: "/brokers",
    requiredCapability: "brokers.access",
    requiredPermission: "brokers.view",
  },
  {
    id: "contracts-payments",
    title: "Contracts & Payments",
    description: "Contracts, payment schedules, collections, and invoices.",
    icon: "🧾",
    path: "/contracts-payments",
    requiredCapability: "contracts_payments.access",
    requiredPermission: "contracts_payments.view",
  },
  {
    id: "finance",
    title: "Accounting",
    description: "Revenue, expenses, cash flow, accounting, and reports.",
    icon: "💰",
    path: "/finance",
    requiredCapability: "finance.access",
    requiredPermission: "finance.view",
  },
  {
    id: "hr",
    title: "Human Resources",
    description: "Employees, attendance, leaves, payroll, and performance.",
    icon: "🧑‍💼",
    path: "/hr",
    requiredCapability: "hr.access",
    requiredPermission: "hr.view",
  },
  {
    id: "reports-ai",
    title: "Reports & AI",
    description: "Reports, analytics, insights, predictions, and alerts.",
    icon: "🤖",
    path: "/reports-ai",
    requiredCapability: "reports_ai.access",
    requiredPermission: "reports_ai.view",
  },
];
