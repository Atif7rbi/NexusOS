# Tenant License State Machine

**Project:** NexusOS Backend

**Document:** Architecture Reference

**Version:** 1.0

**Status:** Frozen

**Last Updated:** July 2026

---

# 1. Purpose

This document defines the official state machine for the Tenant License lifecycle.

It specifies:

* All valid states
* All valid transitions
* Service ownership
* Invalid transitions
* Terminal states
* Time-gated transitions

This document is the single source of truth for every Tenant License state transition.

---

# 2. License States

| State        | Description                       | Current License |
| ------------ | --------------------------------- | :-------------: |
| trial        | Operational trial license         |        ✅        |
| active       | Paid operational license          |        ✅        |
| grace_period | Temporary payment recovery period |        ✅        |
| expired      | Permanently expired license       |        ❌        |
| cancelled    | Intentionally terminated license  |        ❌        |

---

# 3. State Categories

## Current States

Current licenses represent the active commercial entitlement of a Tenant.

```text
trial

active

grace_period
```

Only one Current License may exist per Tenant.

---

## Historical States

Historical licenses remain permanently stored for auditing purposes.

```text
expired

cancelled
```

Historical licenses never become Current again.

---

# 4. State Machine

```text
                    IssueTrialLicense
                           │
                           ▼
                       +---------+
                       |  trial  |
                       +---------+
                            │
            ActivateTenantLicense
                            │
                            ▼
                       +---------+
                       | active  |
                       +---------+
                       ▲        │
                       │        │
 RenewTenantLicense    │        │
 (early renewal)       │        │
                       │        ▼
                 +---------------+
                 | grace_period  |
                 +---------------+
                       │
 RenewTenantLicense    │
 (payment recovery)    │
                       ▼
                    active

grace_period
      │
      │
ExpireTenantLicense
      ▼
+------------+
|  expired   |
+------------+

trial ─────────────────────► cancelled

active ────────────────────► cancelled

grace_period ──────────────► cancelled
```

---

# 5. Transition Matrix

| Service                 | From         | To           |
| ----------------------- | ------------ | ------------ |
| IssueTrialLicense       | none         | trial        |
| ActivateTenantLicense   | trial        | active       |
| RenewTenantLicense      | active       | active       |
| RenewTenantLicense      | grace_period | active       |
| EnterLicenseGracePeriod | active       | grace_period |
| ExpireTenantLicense     | trial        | expired      |
| ExpireTenantLicense     | grace_period | expired      |
| CancelTenantLicense     | trial        | cancelled    |
| CancelTenantLicense     | active       | cancelled    |
| CancelTenantLicense     | grace_period | cancelled    |

---

# 6. Invalid Transitions

The following transitions are prohibited.

| Transition               | Reason                      |
| ------------------------ | --------------------------- |
| active → expired         | Grace Period is mandatory   |
| expired → active         | Requires a new subscription |
| cancelled → active       | Requires a new subscription |
| trial → grace_period     | Trial never enters Grace    |
| trial → trial            | No reaffirmation            |
| active → trial           | Impossible                  |
| expired → grace_period   | Impossible                  |
| cancelled → grace_period | Impossible                  |

Every invalid transition must raise a Domain Exception.

No implicit correction is permitted.

---

# 7. Terminal States

Terminal states cannot transition back into Current states.

```text
expired

cancelled
```

Recovery is always performed through:

```text
StartTenantSubscription
```

A terminated license is never reactivated.

---

# 8. Time-Gated Transitions

Some transitions require temporal validation.

## Trial Expiration

```text
trial

↓

expired
```

Condition:

```text
expires_at <= occurredAt
```

---

## Grace Entry

```text
active

↓

grace_period
```

Condition:

```text
expires_at <= occurredAt
```

---

## Grace Expiration

```text
grace_period

↓

expired
```

Condition:

```text
grace_expires_at <= occurredAt
```

---

## Early Renewal

```text
active

↓

active
```

Condition:

```text
expires_at > occurredAt
```

Anchor:

```text
current expires_at
```

---

## Recovery Renewal

```text
grace_period

↓

active
```

Anchor:

```text
occurredAt
```

---

# 9. Ownership

Every transition is owned by exactly one public Service.

No Service may perform another Service's transition implicitly.

Examples:

```text
RenewTenantLicense
```

never enters Grace Period.

It rejects the request if Grace Period should have already started.

Likewise:

```text
ExpireTenantLicense
```

never skips Grace Period.

The Scheduler must first invoke:

```text
EnterLicenseGracePeriod
```

before ExpireTenantLicense.

---

# 10. Scheduler Behaviour

Automatic jobs never modify state directly.

The official flow is:

```text
LicenseExpiryJob

↓

ReconcileTenantLicenseExpiry

↓

EnterLicenseGracePeriod

↓

ExpireTenantLicense
```

The Application Layer owns orchestration.

The Domain Layer owns state transitions.

---

# 11. Architectural Rules

Every transition must satisfy the following rules:

* Exactly one owning Service.
* Explicit state transition.
* Domain validation before mutation.
* Aggregate locking before validation.
* UTC timestamps only.
* No implicit state correction.
* No nested lifecycle transitions.
* Every successful transition is auditable.

---

# 12. Freeze Status

This State Machine is considered **Frozen v1**.

No transition may be added, removed, or modified without updating both:

* TenantLicenseStateMachine.md
* TenantLicenseDomain.md

These two documents must always remain synchronized.

