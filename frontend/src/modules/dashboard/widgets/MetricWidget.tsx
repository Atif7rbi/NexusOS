import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";

type MetricWidgetProps = {
  label: string;
  value: string;
  change: string;
  tone: "success" | "warning" | "danger" | "primary" | "neutral";
};

/**
 * Renders an executive dashboard metric widget.
 */
export function MetricWidget({ label, value, change, tone }: MetricWidgetProps) {
  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-sm font-bold text-[var(--nexus-muted)]">
            {label}
          </p>
          <p className="mt-3 text-3xl font-black tracking-tight text-[var(--foreground)]">
            {value}
          </p>
        </div>
        <Badge tone={tone}>{change}</Badge>
      </div>
    </Card>
  );
}
