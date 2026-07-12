# Service Standard

**Project:** NexusOS Backend

**Document:** Architecture Standard

**Version:** 1.1

**Status:** Frozen

**Last Updated:** July 2026

---

# 1. Purpose

This document defines the official standard for all Domain Services within NexusOS Backend.

Every public Service must follow this specification.

The goal is to guarantee:

* Consistent architecture
* Predictable behavior
* Explicit business ownership
* Atomic business operations
* Long-term maintainability

---

# 2. Core Principles

Every Service follows the architectural principles of NexusOS.

```text
Database First

Explicit over Magic

One Use Case per Service

Composition over Inheritance

Business Rules inside Services

Authorization outside Services
```

---

# 3. One Use Case per Service

Each public Service represents exactly one business operation.

Examples:

```text
IssueTrialLicense

ActivateTenantLicense

RenewTenantLicense

ChangeTenantPlan

EnableModuleForTenant
```

A Service must never combine multiple independent business decisions.

If two decisions have different ownership, they belong to different Services.

---

# 4. Naming

Service names must describe the business action.

Preferred style:

```text
IssueTrialLicense

RenewTenantLicense

SyncTenantModulesFromPlan

EnableModuleForTenant
```

Avoid generic names such as:

```text
ProcessLicense

ExecutePlan

HandleModule

UpdateTenant
```

---

# 5. Public Contract

Every public Service exposes exactly one public method.

```php
handle(...)
```

No additional business entry points are allowed.

---

# 6. Constructor Injection

Constructor parameters contain only dependencies.

Examples:

* Repositories
* Clock
* Resolvers
* Internal Domain Operations

Runtime identifiers never belong in the constructor.

---

# 7. Runtime Parameters

Business identifiers are passed to:

```text
handle(...)
```

Examples:

```text
tenantId

licenseId

planId

actorUserId

occurredAt
```

Never store runtime identifiers as Service state.

---

# 8. DTO Results

Every successful write Service returns a dedicated Result DTO.

A Result DTO represents the outcome of one Use Case.

DTOs must not expose Eloquent persistence behavior.

---

# 9. Business Rules

Business rules belong inside Domain Services.

Examples:

* State validation
* Transition validation
* Eligibility
* Ownership checks
* Commercial policies

Business rules must never be implemented inside:

* Controllers
* Form Requests
* Jobs
* Commands
* Repositories

---

# 10. Authorization

Authorization is performed before the Service is invoked.

Services assume that the caller is already authorized.

Services never perform:

* Authentication
* Permission evaluation
* Policy checks

---

# 11. Transaction Ownership

Every public write Service owns its transaction.

The transaction begins inside the Service.

The transaction commits only after every mutation succeeds.

Partial commits are forbidden.

---

# 12. Transactional Composition

Some business operations require multiple Domains to participate within a single transaction.

Example:

```text
ExpireTenantLicense

↓

RevokePlanModulesFromTenantOperation
```

To support atomic cross-domain composition, NexusOS distinguishes between two concepts.

---

## Public Write Service

Responsibilities:

* Owns transaction
* Owns aggregate locking
* Owns orchestration
* Owns retry semantics

Public Services may be invoked independently.

---

## Internal Domain Operation

Responsibilities:

* Owns mutation logic
* Owns domain invariants
* Owns audit semantics
* Returns Result DTO

Internal Operations:

* Never start transactions
* Never commit
* Never roll back

They execute inside the transaction owned by the calling Service.

---

## Why Internal Operations Exist

Internal Operations are introduced only when:

* Atomic composition across Domains is required.
* A public Service cannot safely call another public transactional Service.

They are **not** introduced for symmetry or future-proofing.

---

# 13. Aggregate Locking

Business validation occurs only after acquiring the required aggregate locks.

The owning Service defines:

* Lock order
* Validation order
* Mutation order

Lock ordering must remain consistent across the application.

---

# 14. Idempotency

Each Service explicitly defines its retry behavior.

Possible outcomes:

* Retry succeeds without change.
* Retry is rejected.
* Explicit reaffirmation event.

No implicit idempotency is allowed.

---

# 15. Domain Exceptions

Business failures are represented by Domain Exceptions.

Examples:

```text
TenantLicensePastDueException

TrialAlreadyConsumedException

TenantLicenseAlreadyUsesPlanException
```

Services never expose raw database exceptions as business behavior.

Database exceptions remain the final structural safety net.

---

# 16. Audit Ownership

Every successful business mutation is responsible for producing its own Audit records.

Audit semantics belong to the Service that owns the business decision.

The Application Layer never creates business Audit records.

---

# 17. Time Policy

Every Use Case creates exactly one shared UTC timestamp.

All entity mutations and Audit entries generated during that Use Case reuse the same timestamp.

Nested operations must never generate independent timestamps.

---

# 18. Cross-Domain Ownership

Each Domain owns its own decisions.

Example:

```text
TenantLicense Domain

↓

WHEN module synchronization happens
```

```text
TenantModule Domain

↓

HOW module synchronization is performed
```

No Domain may silently assume ownership of another Domain's rules.

---

# 19. Non Goals

Domain Services are not responsible for:

* HTTP
* API responses
* Validation messages
* Request parsing
* Authentication
* Authorization
* View rendering
* CLI formatting

These belong to outer application layers.

---

# 20. Compliance

Every new Domain Service introduced into NexusOS must comply with this standard.

Any intentional deviation must be documented and approved before implementation.

This document is considered the official Service Architecture Standard for NexusOS Backend.

