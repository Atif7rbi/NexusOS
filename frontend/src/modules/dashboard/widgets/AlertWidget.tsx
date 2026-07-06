import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";

type AlertWidgetProps = {
  title: string;
  description: string;
  tone: "success" | "warning" | "danger" | "primary" | "neutral";
};

/**
 * Renders an executive alert widget.
 */
export function AlertWidget({ title, description, tone }: AlertWidgetProps) {
  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-base font-black text-[var(--foreground)]">
            {title}
          </p>
          <p className="mt-2 text-sm leading-6 text-[var(--nexus-muted)]">
            {description}
          </p>
        </div>
        <Badge tone={tone}>{tone}</Badge>
      </div>
    </Card>
  );
}
