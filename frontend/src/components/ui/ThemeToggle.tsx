"use client";

import { useTheme } from "@/providers/ThemeProvider";

/**
 * Renders a simple theme mode switcher.
 */
export function ThemeToggle() {
  const { themeMode, setThemeMode } = useTheme();

  return (
    <button
      type="button"
      onClick={() => setThemeMode(themeMode === "dark" ? "light" : "dark")}
      className="rounded-full border border-[var(--nexus-border)] bg-[var(--nexus-surface)] px-4 py-2 text-sm font-bold text-[var(--foreground)] shadow-sm transition hover:bg-[var(--nexus-surface-soft)]"
    >
      {themeMode === "dark" ? "Light" : "Dark"}
    </button>
  );
}
