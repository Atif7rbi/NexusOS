# Tenant License Domain v1

**Document Status:** Frozen v1 (Architecture Approved)
**Version:** 1.0.0
**Project:** NexusOS Backend
**Architecture:** Database First
**Last Updated:** July 2026

---

# 1. Purpose

The Tenant License Domain is responsible for the complete lifecycle of commercial licenses assigned to tenants.

It defines how a license is created, activated, renewed, suspended, expired, cancelled, and moved between 
commercial plans.

This document is the authoritative specification for the Tenant License Domain and supersedes any design 
discussions or implementation assumptions.

---

# 2. Scope

This Domain owns only the business lifecycle of licenses.

It is responsible for deciding:

* When a license may be created.
* When a license becomes active.
* When a license expires.
* When a tenant enters Grace Period.
* When a tenant leaves Grace Period.
* When a tenant changes commercial plans.
* When module synchronization must occur.

The Domain never owns how module synchronization is implemented.

---

# 3. Out of Scope

The following responsibilities belong to other Domains or future phases:

* Payment Processing
* Billing
* Invoices
* Refunds
* Proration
* Coupons
* Subscription Scheduling
* Payment Gateways
* Permission Evaluation
* Authentication
* Authorization

---

# 4. Domain Ownership

## TenantLicense Domain owns

* License lifecycle
* License status transitions
* License validity
* Commercial plan assignment
* License timestamps
* Renewal rules
* Grace Period rules
* Expiration rules
* Cancellation rules
* Deciding **when** module synchronization occurs

---

## TenantModule Domain owns

* Which modules belong to a Plan
* Enabling plan modules
* Disabling removed plan modules
* Restoring required modules
* Protecting manual / override modules
* Deciding **how** synchronization is performed

---

# 5. Core Principles

The Tenant License Domain follows all NexusOS backend standards.

## Database First

The database is the final source of truth.

Application code validates business rules.

Database constraints validate structural integrity.

---

## Explicit over Magic

Every business transition must have an explicit Use Case.

No implicit state transitions are allowed.

Services must never silently perform another Service's responsibility.

---

## One Use Case per Service

Each public Service represents exactly one business operation.

Examples:

```text
IssueTrialLicense
RenewTenantLicense
CancelTenantLicense
ChangeTenantPlan
```

Services never merge multiple business decisions into one API.

---

## Domain Ownership

Each Domain owns its own business decisions.

TenantLicense decides:

```text
WHEN
```

TenantModule decides:

```text
HOW
```

No Domain may assume ownership of another Domain's responsibilities.

---

## Atomic Business Operations

Every successful business operation either completes entirely or rolls back entirely.

Partial success is never allowed.

---

## UTC Everywhere

All timestamps are stored in UTC.

Tenant localization happens only during presentation.

---

# 6. Terminology

| Term            | Meaning                                                                |
| --------------- | ---------------------------------------------------------------------- |
| Tenant          | Customer organization using NexusOS                                    |
| License         | Commercial entitlement assigned to one Tenant                          |
| Trial           | Operational license with limited lifetime before commercial activation |
| Active          | Fully active paid license                                              |
| Grace Period    | Temporary payment recovery period before expiration                    |
| Expired         | License permanently ended                                              |
| Cancelled       | License terminated intentionally before expiration                     |
| Plan            | Commercial package defining available modules                          |
| Synchronization | Reconciling Tenant Modules with the assigned Plan                      |

---

# 7. Domain Invariants

The following rules are always true.

Violating any invariant is considered a bug.

## One Current License

A Tenant may have only one current License.

Current means:

```text
trial
active
grace_period
```

Historical licenses:

```text
expired
cancelled
```

may exist without limitation.

---

## License History Never Changes

Historical licenses are never deleted.

All lifecycle events remain permanently auditable.

---

## One Meaning per Status

Every status represents exactly one business meaning.

Statuses never overlap.

---

## Trial Is Operational

Trial is not a waiting state.

A Trial License is fully operational.

The Tenant receives Plan entitlements immediately after Trial creation.

---

## Grace Period Is Recoverable

Grace Period is a temporary commercial recovery state.

It is not a terminal state.

---

## Expired Is Terminal

Expired licenses never become active again.

Recovery always creates or starts a new commercial subscription according to business rules.

---

## Cancelled Is Terminal

Cancelled licenses never become active again.

Recovery always requires a new subscription.

---

## Plan Assignment Is Explicit

Every License references exactly one commercial Plan.

Changing the assigned Plan is always an explicit business operation.

---

## Module Synchronization Is Derived

Tenant Modules are derived from the assigned Plan.

The License Domain never owns synchronization logic.

---

# 8. Time Policy

The Domain uses a single UTC timestamp for every Use Case execution.

The timestamp is created once by the outer Service.

All entity mutations and Audit events produced during the same Use Case share the same timestamp.

Nested services and internal operations must never generate independent timestamps.

---

# 9. Aggregate Lock Policy

Concurrency is controlled through Aggregate locking.

Official Aggregate Lock:

```text
Tenant
```

Every Service that creates or mutates a Tenant License must lock the Tenant row before performing business 
validation.

Official lock order:

```text
Tenant

↓

TenantLicense

↓

TenantModule rows (when required)
```

This lock order is mandatory throughout the backend to avoid deadlocks.

---

# 10. Current Lifecycle Services

The Tenant License Domain consists of the following public Services.

```text
IssueTrialLicense

StartTenantSubscription

ActivateTenantLicense

RenewTenantLicense

EnterLicenseGracePeriod

ExpireTenantLicense

CancelTenantLicense

ChangeTenantPlan
```

Each Service represents exactly one business Use Case.

Detailed specifications for every Service are defined in the following sections of this document.

