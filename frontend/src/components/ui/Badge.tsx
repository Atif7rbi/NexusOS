type BadgeTone = "neutral" | "success" | "warning" | "danger" | "primary";

type BadgeProps = {
  children: React.ReactNode;
  tone?: BadgeTone;
};

/**
 * Renders a small status badge.
 */
export function Badge({ children, tone = "neutral" }: BadgeProps) {
  const tones: Record<BadgeTone, string> = {
    neutral: "bg-slate-100 text-slate-700",
    success: "bg-green-50 text-green-700",
    warning: "bg-amber-50 text-amber-700",
    danger: "bg-red-50 text-red-700",
    primary: "bg-blue-50 text-[var(--nexus-primary)]",
  };

  return (
    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-bold ${tones[tone]}`}>
      {children}
    </span>
  );
}
