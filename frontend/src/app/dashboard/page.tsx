import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";
import { SectionHeader } from "@/components/ui/SectionHeader";
import { AppShell } from "@/layout/app-shell/AppShell";

/**
 * Renders the executive dashboard inside the NexusOS app shell.
 */
export default function DashboardPage() {
  return (
    <AppShell>
      <div className="space-y-6">
        <SectionHeader
          eyebrow="Executive Dashboard"
          title="Business Overview"
          description="A high-level command center for revenue, projects, teams, alerts, and reports."
        />

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <Card className="p-5">
            <p className="text-sm font-bold text-[var(--nexus-muted)]">Revenue</p>
            <p className="mt-3 text-3xl font-black">$2.4M</p>
            <Badge tone="success">+12.5%</Badge>
          </Card>

          <Card className="p-5">
            <p className="text-sm font-bold text-[var(--nexus-muted)]">Expenses</p>
            <p className="mt-3 text-3xl font-black">$820K</p>
            <Badge tone="warning">+4.1%</Badge>
          </Card>

          <Card className="p-5">
            <p className="text-sm font-bold text-[var(--nexus-muted)]">Projects</p>
            <p className="mt-3 text-3xl font-black">42</p>
            <Badge tone="primary">8 Critical</Badge>
          </Card>

          <Card className="p-5">
            <p className="text-sm font-bold text-[var(--nexus-muted)]">Employees</p>
            <p className="mt-3 text-3xl font-black">186</p>
            <Badge tone="success">Active</Badge>
          </Card>
        </div>
      </div>
    </AppShell>
  );
}
