import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Input } from "@/components/ui/Input";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { brandConfig } from "@/config/brand";

/**
 * Renders the production-ready login screen.
 */
export function LoginPage() {
  return (
    <main className="min-h-screen bg-[var(--background)] px-6 py-8">
      <div className="mx-auto grid min-h-[calc(100vh-4rem)] w-full max-w-6xl items-center gap-10 lg:grid-cols-[1.1fr_0.9fr]"><div className="fixed left-6 top-6 z-20"><ThemeToggle /></div>
        <section className="space-y-8">
          <div className="inline-flex items-center gap-3 rounded-full border border-blue-900/10 bg-[var(--nexus-surface)]/70 px-4 py-2 text-sm font-medium text-[var(--nexus-primary)] shadow-sm backdrop-blur">
            <span className="h-2.5 w-2.5 rounded-full bg-[var(--nexus-accent)]" />
            White Label Business Platform
          </div>

          <div className="max-w-2xl space-y-5">
            <p className="text-sm font-semibold uppercase tracking-[0.28em] text-[var(--nexus-accent)]">
              {brandConfig.customerName}
            </p>
            <h1 className="text-5xl font-bold tracking-tight text-[var(--foreground)] md:text-6xl">
              {brandConfig.productName}
            </h1>
            <p className="text-xl font-medium text-[var(--nexus-primary)]">
              {brandConfig.productLabel}
            </p>
            <p className="max-w-xl text-base leading-8 text-[var(--nexus-muted)]">
              A modern operating layer for executives, projects, teams, finance,
              documents, and future AI-powered business intelligence.
            </p>
          </div>

          <div className="grid max-w-2xl gap-4 sm:grid-cols-3">
            <Card className="p-5">
              <p className="text-2xl font-bold text-[var(--foreground)]">Core</p>
              <p className="mt-2 text-sm text-[var(--nexus-muted)]">
                Unified business foundation.
              </p>
            </Card>
            <Card className="p-5">
              <p className="text-2xl font-bold text-[var(--foreground)]">Modules</p>
              <p className="mt-2 text-sm text-[var(--nexus-muted)]">
                CRM, Projects, Finance, HR.
              </p>
            </Card>
            <Card className="p-5">
              <p className="text-2xl font-bold text-[var(--foreground)]">License</p>
              <p className="mt-2 text-sm text-[var(--nexus-muted)]">
                Plans, trials, and access.
              </p>
            </Card>
          </div>
        </section>

        <Card className="p-8">
          <div className="mb-8">
            <p className="text-sm font-semibold uppercase tracking-[0.22em] text-[var(--nexus-accent)]">
              Secure Access
            </p>
            <h2 className="mt-3 text-3xl font-bold text-[var(--foreground)]">
              Sign in to NexusOS
            </h2>
            <p className="mt-3 text-sm leading-6 text-[var(--nexus-muted)]">
              Access your organization workspace, modules, and executive
              command center.
            </p>
          </div>

          <form className="space-y-5">
            <Input
              id="email"
              label="Email address"
              type="email"
              placeholder="name@company.com"
            />
            <Input
              id="password"
              label="Password"
              type="password"
              placeholder="Enter your password"
            />

            <div className="flex items-center justify-between text-sm">
              <label className="flex items-center gap-2 text-[var(--nexus-muted)]">
                <input type="checkbox" className="h-4 w-4 rounded border-gray-300" />
                Remember me
              </label>
              <button
                type="button"
                className="font-semibold text-[var(--nexus-primary)]"
              >
                Forgot password?
              </button>
            </div>

            <Button type="submit">Sign in</Button>
          </form>

          <div className="mt-8 rounded-[var(--radius-lg)] bg-[var(--nexus-surface-soft)] p-4 text-sm text-[var(--nexus-muted)]">
            License status will control modules, trial access, users, branches,
            and subscription duration.
          </div>
        </Card>
      </div>
    </main>
  );
}
