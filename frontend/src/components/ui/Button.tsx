type ButtonProps = {
  children: React.ReactNode;
  type?: "button" | "submit";
  variant?: "primary" | "secondary";
};

/**
 * Renders a reusable NexusOS button.
 */
export function Button({
  children,
  type = "button",
  variant = "primary",
}: ButtonProps) {
  const baseClass =
    "inline-flex h-12 items-center justify-center rounded-[var(--radius-md)] px-5 text-sm font-semibold transition";

  const variantClass =
    variant === "primary"
      ? "bg-[var(--nexus-primary)] text-white hover:bg-[var(--nexus-primary-dark)]"
      : "border border-[var(--nexus-border)] bg-white text-[var(--foreground)] hover:bg-[var(--nexus-surface-soft)]";

  return (
    <button type={type} className={`${baseClass} ${variantClass}`}>
      {children}
    </button>
  );
}
