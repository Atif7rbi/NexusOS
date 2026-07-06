"use client";

import { createContext, useContext, useEffect, useMemo, useState } from "react";
import { THEME_STORAGE_KEY, type ThemeMode } from "@/core/theme/theme";

type ActiveTheme = "light" | "dark";

type ThemeContextValue = {
  themeMode: ThemeMode;
  setThemeMode: (mode: ThemeMode) => void;
};

const ThemeContext = createContext<ThemeContextValue | null>(null);

/**
 * Resolves the active theme from user preference and system preference.
 */
function resolveTheme(mode: ThemeMode): ActiveTheme {
  if (mode === "system") {
    return window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light";
  }

  return mode;
}

/**
 * Returns the stored user theme preference.
 */
function getInitialThemeMode(): ThemeMode {
  if (typeof window === "undefined") {
    return "system";
  }

  const storedTheme = localStorage.getItem(THEME_STORAGE_KEY);

  if (
    storedTheme === "light" ||
    storedTheme === "dark" ||
    storedTheme === "system"
  ) {
    return storedTheme;
  }

  return "system";
}

/**
 * Provides theme state and applies it to the document.
 */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [themeMode, setThemeModeState] =
    useState<ThemeMode>(getInitialThemeMode);

  useEffect(() => {
    const activeTheme = resolveTheme(themeMode);

    document.documentElement.dataset.theme = activeTheme;
    localStorage.setItem(THEME_STORAGE_KEY, themeMode);
  }, [themeMode]);

  const value = useMemo(
    () => ({
      themeMode,
      setThemeMode: setThemeModeState,
    }),
    [themeMode],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

/**
 * Returns the current NexusOS theme context.
 */
export function useTheme() {
  const context = useContext(ThemeContext);

  if (!context) {
    throw new Error("useTheme must be used inside ThemeProvider.");
  }

  return context;
}
