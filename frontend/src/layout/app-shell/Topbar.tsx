import { ThemeToggle } from "@/components/ui/ThemeToggle";

/**
 * Renders the main application top navigation bar.
 */
export function Topbar() {
  return (
    <header className="flex h-20 items-center justify-between border-b border-[var(--nexus-border)] bg-[var(--nexus-surface)] px-6">
      <div>
        <p className="text-sm font-semibold text-[var(--nexus-muted)]">
          Welcome back
        </p>
        <h1 className="text-xl font-black tracking-tight text-[var(--foreground)]">
          Executive Command Center
        </h1>
      </div>

      <div className="flex items-center gap-3">
        <div className="hidden h-11 w-72 items-center rounded-[var(--radius-md)] border border-[var(--nexus-border)] bg-[var(--nexus-surface-soft)] px-4 text-sm text-[var(--nexus-muted)] md:flex">
          Search NexusOS...
        </div>
        <ThemeToggle />
        <button
          type="button"
          className="grid h-11 w-11 place-items-center rounded-full border border-[var(--nexus-border)] bg-[var(--nexus-surface-soft)] text-sm font-bold"
        >
          AT
        </button>
      </div>
    </header>
  );
}
