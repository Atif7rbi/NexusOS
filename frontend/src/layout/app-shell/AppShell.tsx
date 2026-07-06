import { Sidebar } from "@/layout/app-shell/Sidebar";
import { Topbar } from "@/layout/app-shell/Topbar";

type AppShellProps = {
  children: React.ReactNode;
};

/**
 * Renders the main authenticated application shell.
 */
export function AppShell({ children }: AppShellProps) {
  return (
    <div className="flex min-h-screen bg-[var(--background)] text-[var(--foreground)]">
      <Sidebar />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar />
        <main className="flex-1 p-6">{children}</main>
      </div>
    </div>
  );
}
