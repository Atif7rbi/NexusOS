import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Divider } from "@/components/ui/Divider";
import { Input } from "@/components/ui/Input";
import { SectionHeader } from "@/components/ui/SectionHeader";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

/**
 * Renders the internal NexusOS UI showcase page.
 */
export default function UIShowcasePage() {
  return (
    <main className="min-h-screen bg-[var(--background)] px-6 py-8 text-[var(--foreground)]">
      <div className="mx-auto max-w-6xl space-y-10">
        <div className="flex items-start justify-between gap-6">
          <SectionHeader
            eyebrow="Nexus UI"
            title="Design System Showcase"
            description="Internal reference page for testing reusable NexusOS UI components across light and dark themes."
          />
          <ThemeToggle />
        </div>

        <Card className="p-6">
          <SectionHeader
            title="Buttons"
            description="Reusable actions used across all NexusOS modules."
          />
          <Divider />
          <div className="mt-6 flex flex-wrap gap-4">
            <Button>Primary Button</Button>
            <Button variant="secondary">Secondary Button</Button>
          </div>
        </Card>

        <Card className="p-6">
          <SectionHeader
            title="Inputs"
            description="Base form fields used in authentication, settings, and modules."
          />
          <Divider />
          <div className="mt-6 grid gap-5 md:grid-cols-2">
            <Input
              id="showcase-email"
              label="Email Address"
              type="email"
              placeholder="name@company.com"
            />
            <Input
              id="showcase-password"
              label="Password"
              type="password"
              placeholder="Enter password"
            />
          </div>
        </Card>

        <Card className="p-6">
          <SectionHeader
            title="Badges"
            description="Small visual indicators for status, plans, alerts, and module states."
          />
          <Divider />
          <div className="mt-6 flex flex-wrap gap-3">
            <Badge>Neutral</Badge>
            <Badge tone="primary">Primary</Badge>
            <Badge tone="success">Success</Badge>
            <Badge tone="warning">Warning</Badge>
            <Badge tone="danger">Danger</Badge>
          </div>
        </Card>

        <Card className="p-6">
          <SectionHeader
            title="Cards"
            description="Reusable content surfaces for dashboards, modules, and settings."
          />
          <Divider />
          <div className="mt-6 grid gap-4 md:grid-cols-3">
            <Card className="p-5">
              <p className="text-sm font-bold text-[var(--nexus-muted)]">
                Revenue
              </p>
              <p className="mt-3 text-3xl font-bold">$248K</p>
              <Badge tone="success">+12.4%</Badge>
            </Card>

            <Card className="p-5">
              <p className="text-sm font-bold text-[var(--nexus-muted)]">
                Active Projects
              </p>
              <p className="mt-3 text-3xl font-bold">42</p>
              <Badge tone="primary">On Track</Badge>
            </Card>

            <Card className="p-5">
              <p className="text-sm font-bold text-[var(--nexus-muted)]">
                License Status
              </p>
              <p className="mt-3 text-3xl font-bold">Active</p>
              <Badge tone="warning">Renewal Soon</Badge>
            </Card>
          </div>
        </Card>
      </div>
    </main>
  );
}
