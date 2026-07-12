# Audit Log Standard

**Project:** NexusOS Backend

**Document:** Architecture Standard

**Version:** 1.1

**Status:** Frozen

**Last Updated:** July 2026

---

# 1. Purpose

This document defines the official Audit Log standard used throughout NexusOS Backend.

The Audit Log records successful business decisions.

It provides:

* Business accountability
* Operational traceability
* Regulatory compliance
* Historical reconstruction

Audit Logs are a permanent historical record and must never be treated as application logs.

---

# 2. Scope

The Audit Log records **business events only**.

Examples:

* License issued
* License activated
* License expired
* Module enabled
* Module revoked
* Tenant suspended
* Role assigned

The Audit Log does **not** record:

* HTTP requests
* Validation failures
* Authentication attempts
* Authorization failures
* SQL queries
* Debug information
* Performance metrics

---

# 3. Core Principles

Every Audit entry follows these principles.

---

## Business Decisions Only

Audit records successful business decisions.

A failed operation produces no Audit record.

Business failures are represented by Domain Exceptions.

Operational failures belong to monitoring systems.

---

## Immutable History

Audit records are append-only.

Existing records must never be modified.

Existing records must never be deleted.

---

## Atomicity

Audit creation participates in the same database transaction as the business mutation.

If the business operation rolls back,

the Audit record rolls back.

No orphan Audit entries may exist.

---

## Explicit Ownership

The Service that owns the business decision owns the Audit record.

Controllers

Jobs

Commands

Application Services

must never create business Audit records.

---

# 4. One Entity per Audit Row

Every Audit row represents one entity.

Each row contains:

```text
entity_type

entity_id
```

Only one entity may be referenced per row.

Example:

```text
TenantLicense
```

or

```text
Tenant
```

or

```text
TenantModule
```

Never multiple entities in one row.

---

# 5. Multiple Audit Rows per Use Case

A single Use Case may legitimately generate multiple Audit rows.

Example:

```text
ExpireTenantLicense
```

creates:

```text
TenantLicense

↓

tenant_license.expired
```

```text
Tenant

↓

tenant.suspended_due_to_license_expiration
```

```text
TenantModule

↓

tenant_module.plan_entitlement_revoked
```

Each row represents a different entity.

All rows belong to the same database transaction.

---

# 6. Audit Events

Audit events describe **what happened** to the entity.

They do not describe why.

Good examples:

```text
tenant_license.activated

tenant_license.expired

tenant_module.enabled

tenant_module.disabled

tenant_module.plan_entitlement_revoked
```

Avoid embedding business reasons inside event names.

---

# 7. Metadata

Business context belongs inside:

```text
metadata
```

Examples:

```text
trigger

license_id

reason

plan_id

sync_source

billing_reference
```

Metadata explains **why** the event happened.

Event names explain **what** happened.

---

# 8. Before / After

Every Audit entry records:

```text
before

after
```

Only meaningful business fields are included.

Internal framework fields should not be duplicated.

---

# 9. Snapshot Policy

Snapshots may be stored when necessary for historical reconstruction.

Snapshots supplement,

but never replace,

before/after values.

---

# 10. Time Policy

Every Audit entry uses the shared Use Case timestamp.

One business operation

↓

One UTC timestamp

↓

All related Audit rows

Nested services must never generate independent timestamps.

---

# 11. Actor Attribution

Audit records may contain:

```text
actor_user_id
```

The actor represents attribution only.

Authentication

Authorization

User existence

are validated before entering the Domain Service.

System actions:

```text
actor_user_id = null
```

Human actions:

```text
real user id
```

No artificial "system user" shall exist.

---

# 12. Idempotency

Idempotent retries never create duplicate Audit records.

Exceptions:

Explicit reaffirmation events documented by the owning Domain.

Example:

```text
tenant_module.override_reaffirmed
```

Reaffirmation events must be explicitly documented.

Implicit reaffirmation is forbidden.

---

# 13. Business Failure

Business failures never generate Audit records.

Examples:

```text
TrialAlreadyConsumedException

TenantLicensePastDueException

InvalidTenantLicenseTransitionException
```

The transaction rolls back.

The Audit rolls back.

Monitoring and alerting belong to operational tooling.

---

# 14. Operational Logging

Operational events belong to dedicated systems.

Examples:

* Scheduler failures
* Queue retries
* Payment gateway failures
* HTTP failures
* Infrastructure events

These must never pollute the business Audit Log.

---

# 15. Cross-Domain Operations

Cross-domain business operations may generate Audit rows from multiple Domains.

Each Domain owns the Audit semantics for the entities it owns.

Example:

```text
TenantLicense Domain

↓

tenant_license.expired
```

```text
TenantModule Domain

↓

tenant_module.plan_entitlement_revoked
```

No Domain may create Audit rows describing another Domain's business decision.

---

# 16. Governance

Every new Audit event must satisfy:

* Represents one business decision.
* References one entity.
* Uses one entity_type.
* Participates in the business transaction.
* Has a clearly documented owner.
* Follows the event naming convention.

---

# 17. Non Goals

The Audit Log is not:

* Application Log
* Debug Log
* Security Log
* Payment Log
* Queue Log
* HTTP Access Log

Dedicated systems should exist for those concerns.

---

# 18. Compliance

All NexusOS Domains must comply with this standard.

Intentional deviations require an architectural review before implementation.

This document is the official Audit Log Standard for NexusOS Backend.

