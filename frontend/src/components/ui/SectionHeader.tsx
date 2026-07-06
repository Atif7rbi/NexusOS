type SectionHeaderProps = {
  eyebrow?: string;
  title: string;
  description?: string;
};

/**
 * Renders a reusable section heading.
 */
export function SectionHeader({
  eyebrow,
  title,
  description,
}: SectionHeaderProps) {
  return (
    <header className="space-y-2">
      {eyebrow ? (
        <p className="text-xs font-bold uppercase tracking-[0.22em] text-[var(--nexus-accent)]">
          {eyebrow}
        </p>
      ) : null}
      <h2 className="text-2xl font-bold tracking-tight text-slate-950">
        {title}
      </h2>
      {description ? (
        <p className="max-w-2xl text-sm leading-6 text-[var(--nexus-muted)]">
          {description}
        </p>
      ) : null}
    </header>
  );
}
