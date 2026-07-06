type CardProps = {
  children: React.ReactNode;
  className?: string;
};

/**
 * Renders a reusable surface card.
 */
export function Card({ children, className = "" }: CardProps) {
  return (
    <section
      className={`rounded-[var(--radius-xl)] border border-[var(--nexus-border)] bg-[var(--nexus-surface)] shadow-sm ${className}`}
    >
      {children}
    </section>
  );
}
