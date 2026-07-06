import { brandConfig } from "@/config/brand";
import { buildNavigation } from "@/core/navigation/navigationBuilder";
import { getLicenseSummary } from "@/core/registry/availableModules";
import { SidebarItem } from "@/layout/app-shell/SidebarItem";

/**
 * Renders the main application sidebar.
 */
export function Sidebar() {
  const navigation = buildNavigation();
  const license = getLicenseSummary();

  return (
    <aside className="hidden h-screen w-72 shrink-0 border-l border-[var(--nexus-border)] bg-[var(--nexus-surface)] p-5 lg:flex lg:flex-col">
      <div className="mb-8">
        <div className="flex items-center gap-3">
          <div className="grid h-11 w-11 place-items-center rounded-[var(--radius-lg)] bg-[var(--nexus-primary)] text-lg font-black text-white">
            N
          </div>

          <div>
            <p className="text-lg font-black tracking-tight text-[var(--foreground)]">
              {brandConfig.productName}
            </p>

            <p className="text-xs font-semibold text-[var(--nexus-muted)]">
              {brandConfig.customerName}
            </p>
          </div>
        </div>
      </div>

      <nav className="flex-1 space-y-1">
        {navigation.map((item, index) => (
          <SidebarItem
            key={item.id}
            item={item}
            isActive={index === 0}
          />
        ))}
      </nav>

      <div className="mt-6 rounded-[var(--radius-lg)] bg-[var(--nexus-surface-soft)] p-4">
        <p className="text-sm font-bold text-[var(--foreground)]">
          {license.plan.toUpperCase()} License
        </p>

        <p className="mt-1 text-xs leading-5 text-[var(--nexus-muted)]">
          Up to {license.maxUsers} users · Expires {license.expiresAt}
        </p>
      </div>
    </aside>
  );
}
