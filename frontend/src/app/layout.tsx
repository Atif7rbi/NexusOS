import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { ThemeProvider } from "@/providers/ThemeProvider";
import "./globals.css";
import "@/styles/brand-v2.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: {
    default: "NexusOS",
    template: "%s | NexusOS",
  },
  description: "نظام تشغيل الأعمال المتكامل لإدارة الشركات والمشاريع والعملاء والعمليات من منصة واحدة.",
  icons: {
    icon: "/brand/nexusos-icon.svg",
    shortcut: "/brand/nexusos-icon.svg",
    apple: "/brand/nexusos-icon.svg",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="ar"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full">
        <ThemeProvider>{children}</ThemeProvider>
      </body>
    </html>
  );
}
