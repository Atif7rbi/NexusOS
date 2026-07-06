# NexusOS Core Architecture (Phase 1)

## Status

Phase 1: ✅ Completed

---

# Core Principles

NexusOS is built as a Platform, not a traditional ERP application.

The architecture follows:

Platform
    ↓
Core Engines
    ↓
Business Modules
    ↓
Workspaces
    ↓
Widgets

---

# Core Engines

- Theme Engine
- Identity Engine
- Runtime Layer
- License Engine
- Permission Engine
- Navigation Builder
- Workspace Engine
- Module Registry
- Widget Registry

---

# Folder Responsibilities

src/app
Application routes only.

src/core
Platform engines.

src/modules
Business modules.

src/components/ui
Reusable UI components.

src/layout
Application shell.

src/providers
React providers.

src/config
Global configuration only.

---

# Module Rules

Every business module must contain:

module.ts

pages/

widgets/

data/

No module may modify Core directly.

---

# Workspace Rules

Every user works inside a Workspace.

A Workspace contains:

- Layout
- Widgets
- Preferences

Dashboards are considered Workspace layouts.

---

# Development Rules

1. Never break Core compatibility.
2. Add layers instead of replacing them.
3. Every new module must provide module.ts.
4. Widgets must register through Widget Registry.
5. Business logic belongs to Modules.
6. Platform logic belongs to Core.

---

Phase 1 Baseline

Version:
v0.1.0-alpha
