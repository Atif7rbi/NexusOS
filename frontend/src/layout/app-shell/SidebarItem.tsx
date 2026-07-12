import type { NavigationItem } from "@/core/navigation/navigation";

type SidebarItemProps = {
  item: NavigationItem;
  isActive?: boolean;
};

/**
 * Renders a single sidebar navigation item.
 */
export function SidebarItem({ item, isActive = false }: SidebarItemProps) {
  return (
    <a
      href={item.path}
      className={[
        "flex items-center justify-between rounded-[var(--radius-md)] px-3 py-2.5 text-sm font-semibold transition",
        isActive
          ? "bg-[var(--nexus-primary)] text-white shadow-sm"
          : "text-[var(--nexus-muted)] hover:bg-[var(--nexus-surface-soft)] hover:text-[var(--foreground)]",
      ].join(" ")}
    >
      <span className="flex items-center gap-3">
        <span aria-hidden="true">{item.icon}</span>
        <span>{item.title}</span>
      </span>
    </a>
  );
}
