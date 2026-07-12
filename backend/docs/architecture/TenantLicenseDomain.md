# TenantLicense Domain

> **WARNING**
> This document describes the implemented contract.
> If implementation and this document ever disagree, **implementation must
> be treated as the source of truth** until this document is updated.
> During this domain's construction, several early exception names and
> operation contracts changed during implementation (see FD-013 to
> FD-016). This document reflects the final, verified state — not the
> original design intent.

---

## 1. Document Information

| Field | Value |
|---|---|
| Version | v1 |
| Status | **Frozen** |
| Git Tag | `tenant-license-domain-v1` |
| Last Commit | `bb1c35d` — "TenantLicense Domain: complete lifecycle services and tests" |
| Test Coverage | 221 tests / 706 assertions / 0 failures |
| Last Updated | 13 يوليو 2026 |

### 1.1 Quick Reference

| Metric | Value |
|---|---:|
| Lifecycle Services | 8 |
| Time Resolvers | 3 |
| Internal Operations | 2 |
| Transition Policy | 1 |
| Result DTOs | 8 |
| Exception Classes | 9 |
| Frozen Decisions | 16 |
| Automated Tests | 221 |
| Assertions | 706 |
| Failures | 0 |
| Frozen Tag | `tenant-license-domain-v1` |
| Frozen Commit | `bb1c35d` |

### 1.2 Reading Guide

| Reader Goal | Recommended Sections |
|---|---|
| Understand the domain quickly | §3 Scope → §4 Overview → §9 State Machine |
| Implement or modify a lifecycle service | §5 Principles → §7 Aggregate Ownership → §12 Services → §15 Transactions |
| Understand a rejected transition | §11 Exception Architecture → §16 Idempotency → §19 Frozen Decisions |
| Modify database rules | §8 Database Contract → §9 State Machine → §17 Testing Architecture |
| Investigate entitlement behavior | §6 Responsibility Matrix → §13 Operations → §14 Audit Contract |

---

## 2. Decision Index

جدول محتويات سريع لقسم Frozen Decisions (§17)، لتفادي صعوبة البحث اليدوي مع نمو عدد القرارات مستقبلاً.

| Decision | Topic |
|---|---|
| FD-001 | Cancellation Reason (mandatory) |
| FD-002 | Expire Idempotency (the only idempotent service) |
| FD-003 | Trial One-Time Policy |
| FD-004 | Activation Grandfathering (`plan.is_active` not required) |
| FD-005 | Plan Change Requires Active Target Plan |
| FD-006 | Grace Anchor = `expires_at`, not scheduler time |
| FD-007 | Renewal Past-Due Rejection |
| FD-008 | Grace Entry Always Syncs Modules |
| FD-009 | Idempotent Path Never Repairs Side Effects |
| FD-010 | Revoke Operation Has No Public Service (v1) |
| FD-011 | Plan Change: Structural Checks Before Commercial Checks |
| FD-012 | Plan Change: Compare Unit + Count Together |
| FD-013 | Revoke Operation: Corrected Event Name & Trigger Contract |
| FD-014 | Plan Change: Actual Exception Name (`planAlreadyAssigned`) |
| FD-015 | Start Subscription: Current-License-Only Check |
| FD-016 | Plan Change: Trial Excluded (Reversed Earlier Decision) |

---

## 3. Scope

يغطي هذا المستند العقد الكامل لـ `TenantLicense Domain`: دورة حياة التراخيص التجارية للـ Tenants داخل NexusOS، من الإصدار التجريبي وحتى الإلغاء أو الانتهاء، بما في ذلك التفاعل الذري مع `TenantModule Domain` لمزامنة الاستحقاقات.

**خارج النطاق (v1):**
- Billing / Proration / Refunds (تحدث خارج هذا الـDomain، الخدمات هنا تستقبل مراجع دفع فقط للتدقيق).
- Scheduled/Deferred plan changes ("cancel at period end").
- Per-plan grace duration (السياسة عالمية حاليًا عبر Config).
- Trial plan changes (`ChangeTrialPlan` غير مصمم).

---

## 4. Domain Overview

`TenantLicense` هو الكيان المركزي الذي يمثل استحقاق Tenant الحالي (أو التاريخي) للوصول إلى موديولات النظام، مربوطًا بخطة تجارية (`Plan`) وفترة زمنية محددة (أو غير محددة في حالة Lifetime).

الدومين مبني على ثمانية خدمات دورة حياة (Lifecycle Services)، وطبقة سياسة مستقلة (`TenantLicenseTransitionRules`) تفصل قواعد الأهلية عن تنفيذ الـMutation، وعملية مزامنة/سحب مشتركة مع `TenantModule Domain`.

### 4.1 Lifecycle Summary

| Intent | Owning Service | Result |
|---|---|---|
| Issue the one-time trial | `IssueTrialLicense` | New `trial` license |
| Activate an existing trial | `ActivateTenantLicense` | `trial → active` |
| Renew an active or grace license | `RenewTenantLicense` | `active → active` or `grace_period → active` |
| Enter grace after paid expiry | `EnterLicenseGracePeriod` | `active → grace_period` |
| Expire an eligible license | `ExpireTenantLicense` | `trial/grace_period → expired` |
| Cancel immediately | `CancelTenantLicense` | `trial/active/grace_period → cancelled` |
| Change the plan of an active license | `ChangeTenantPlan` | `active → active` on another plan |
| Start a direct subscription | `StartTenantSubscription` | New `active` subscription license |

---

## 5. Architecture Principles

الروح المعمارية التي بُني عليها هذا الدومين بأكمله، مجمّعة في مكان واحد بدل توزّعها ضمنيًا عبر الأقسام:

```
1. Database First — البنية تُصمَّم من قاعدة البيانات صعودًا، لا العكس.

2. Database is the last line of defense — الـService تتحقق أولاً؛
   الـCHECK Constraints شبكة أمان نهائية، وليست نقطة الاكتشاف الأولى.

3. One Service owns one transition — كل خدمة تملك انتقال حالة واحدًا
   (أو مسارين مرتبطين لنفس النية التجارية، كما في RenewTenantLicense).

4. Outer Service owns the transaction — الخدمة الخارجية (Use Case) هي
   المالك الوحيد للـTransaction والـLock Ordering العام.

5. Internal Operations never own a transaction — أي عملية مركّبة
   (SyncTenantModulesFromPlanOperation، RevokePlanModulesFromTenantOperation)
   تفترض أن القفل والـTransaction محمولان من الخارج.

6. Explicit over Magic — لا قيم سحرية أو استدلال ضمني (مثال: تمرير
   نفس plan_id لا يعني "أعد المزامنة فقط"، بل حالة مرفوضة صراحة).

7. Scheduler delay must never grant additional entitlement time —
   أي تأخر في التشغيل الآلي لا يمنح Tenant مهلة أو وقتًا إضافيًا مستحقًا.

8. Domain Services never trust the Caller for temporal/state eligibility —
   إعادة التحقق تحت القفل دائمًا، بغض النظر عن مصدر الاستدعاء.
```

---

## 6. Responsibility Matrix

| Component | Owns |
|---|---|
| `Tenant` | Concurrency Aggregate Lock |
| `TenantLicense` | Lifecycle state and period fields |
| `TenantModule` | Entitlement rows (per source: plan/manual/trial/promo/override) |
| `SyncTenantModulesFromPlanOperation` | Entitlement synchronization (HOW) |
| `RevokePlanModulesFromTenantOperation` | Entitlement revocation on license end |
| `TrialPeriodResolver` / `SubscriptionPeriodResolver` / `GracePeriodResolver` | Time calculation (WHEN periods end) |
| `TenantLicenseTransitionRules` | Eligibility & invariants (pure policy, no I/O) |
| `TenantLicense Lifecycle Services` (×8) | WHEN a transition happens, transaction ownership, audit |

---

## 7. Aggregate Ownership

```
Tenant Row = Concurrency Aggregate Lock
```

أي خدمة تُنشئ أو تُغيّر `TenantLicense` يجب أن تبدأ بـ:
```
SELECT Tenant FOR UPDATE
```
قبل أي Validation أو Mutation. ترتيب الأقفال الثابت في كل الدومين:

```
Tenant
  ↓
TenantLicense
  ↓
TenantModule rows (ORDER BY id FOR UPDATE)
```

الـ Outer Service (خدمة الـ TenantLicense المستدعية) هي دائمًا مالكة ترتيب الأقفال العام، وأي Internal Operation مُركَّبة (§11) تفترض أن القفل محمول أصلًا من الخارج ولا تعيد طلبه.

---

## 8. Database Contract

### 8.1 `plans`

| Column | Type | Rule |
|---|---|---|
| `billing_period_unit` | `VARCHAR(20)` | `month` \| `year` \| `lifetime` (ليس PostgreSQL ENUM حقيقي — لسهولة التعديل) |
| `billing_period_count` | `integer nullable` | `month` → `IN (1,3,6,10)`؛ `year` → `>= 1`؛ `lifetime` → `NULL` |
| `is_active` | `boolean` | يتحكم فقط بإصدار/اعتماد جديد (انظر §9.3 وFD-004) |

```sql
CHECK (
    (billing_period_unit = 'month' AND billing_period_count IN (1, 3, 6, 10))
    OR
    (billing_period_unit = 'year' AND billing_period_count IS NOT NULL AND billing_period_count >= 1)
    OR
    (billing_period_unit = 'lifetime' AND billing_period_count IS NULL)
)
```

### 8.2 `tenant_licenses`

| Column | Type | Rule |
|---|---|---|
| `status` | enum-like string | `trial`, `active`, `grace_period`, `expired`, `cancelled` |
| `license_origin` | string | `trial` \| `subscription` — لا يتغير أبدًا بعد الإنشاء |
| `starts_at` | `timestamptz` | يُعاد ضبطه فقط عند: التفعيل، استرجاع الـGrace، إعادة بدء الفترة في `ChangeTenantPlan` |
| `expires_at` | `timestamptz nullable` | `null` فقط لـ Lifetime |
| `grace_ends_at` | `timestamptz nullable` | غير `null` فقط أثناء `status = grace_period` |

### 8.3 CHECK Constraints

#### `tenant_licenses_status_check`

```sql
CHECK (
    status IN (
        'trial',
        'active',
        'grace_period',
        'expired',
        'cancelled'
    )
)
```

#### `tenant_licenses_grace_period_check`

```sql
CHECK (
    grace_ends_at IS NULL
    OR (
        expires_at IS NOT NULL
        AND grace_ends_at > expires_at
    )
)
```

#### `tenant_licenses_license_origin_check`

```sql
CHECK (
    license_origin IN ('trial', 'subscription')
)
```

> **Policy-level invariant:** الربط الكامل بين `status` وحقول الفترة
> (`expires_at`, `grace_ends_at`) يُفرض داخل
> `TenantLicenseTransitionRules::assertPeriodConsistency()`.
> قاعدة البيانات تفرض القواعد البنيوية العامة أعلاه، بينما الـPolicy
> تفرض المصفوفة التفصيلية لكل حالة ونوع خطة.

### 8.4 Partial Unique Index

الفهرس الجزئي الفعلي هو فهرس واحد مشترك:

```sql
CREATE UNIQUE INDEX one_current_license_per_tenant
ON tenant_licenses (tenant_id)
WHERE status IN ('trial', 'active', 'grace_period');
```

وظيفته منع وجود أكثر من `CURRENT_LICENSE` واحدة للـTenant، وهو خط الدفاع
الأخير بعد قفل صف الـTenant. يخدم كلًا من:

- `IssueTrialLicense`
- `StartTenantSubscription`
- أي مسار مستقبلي ينشئ رخصة حالية جديدة

لا يفرض هذا الفهرس قاعدة "Trial واحدة تاريخيًا"؛ تلك قاعدة Business مستقلة
تملكها `IssueTrialLicense` عبر `license_origin = trial`.

### 8.5 Current License Definition

```php
TenantLicense::CURRENT_STATUSES = ['trial', 'active', 'grace_period']
```

يُستخدم هذا التعريف حصريًا داخل Model كـ Constant واحد؛ لا تُكرر القائمة داخل أي Service.

---

## 9. State Machine

### 9.1 State Diagram

```text
                       Activate
          ┌────────────────────────────────┐
          │                                ▼
       trial ── Expire ───────────────► expired
          │
          └── Cancel ─────────────────► cancelled

                       Renew (early)
                  ┌──────────────────────┐
                  │                      │
                  ▼                      │
                active ── Enter Grace ─► grace_period
                  │                          │
                  │                          ├── Renew (recovery) ─► active
                  │                          ├── Expire ───────────► expired
                  └── Cancel ────────────────┴── Cancel ───────────► cancelled

New records:
  IssueTrialLicense        → trial
  StartTenantSubscription  → active

Terminal records:
  expired
  cancelled

A new commercial lifecycle after a terminal record requires a new
TenantLicense row through StartTenantSubscription.
```

### 9.2 Transition Matrix

| From | To | Owning Service | Temporal Condition |
|---|---|---|---|
| — | `trial` | `IssueTrialLicense` | لا يوجد سجل تاريخي |
| — | `active` | `StartTenantSubscription` | لا يوجد `CURRENT_LICENSE` |
| `trial` | `active` | `ActivateTenantLicense` | لا يوجد |
| `active` | `active` | `RenewTenantLicense` (early) | `expires_at > now` |
| `active` | `grace_period` | `EnterLicenseGracePeriod` | `expires_at <= now` |
| `grace_period` | `active` | `RenewTenantLicense` (recovery) | `grace_ends_at > now` |
| `trial` | `expired` | `ExpireTenantLicense` | `expires_at <= now` |
| `grace_period` | `expired` | `ExpireTenantLicense` | `grace_ends_at <= now` |
| `expired` | `expired` | `ExpireTenantLicense` | idempotent no-op |
| `trial`/`active`/`grace_period` | `cancelled` | `CancelTenantLicense` | لا يوجد (قرار إرادي فوري) |
| `active` | `active` (new plan) | `ChangeTenantPlan` | لا يوجد |

**ممنوع صراحة:** `active → expired` مباشرة (يجب المرور عبر `grace_period`)، وأي انتقال من `expired`/`cancelled` عدا `expired → expired` (idempotent).

### 9.3 Lifetime Rules

```
Lifetime license:
  expires_at = null
  grace_ends_at = null
  status = active (دائمًا، بعد التفعيل/الاعتماد)
```

- لا تقبل: `RenewTenantLicense`, `EnterLicenseGracePeriod`, `ExpireTenantLicense`.
- تقبل: `CancelTenantLicense`, `ChangeTenantPlan` (لكن `lifetime → finite` مرفوض دائمًا داخل `ChangeTenantPlan`، بغض النظر عن حالة الخطة الهدف — ترتيب فحص بنيوي يسبق أي فحص تجاري، انظر FD-011).

---

## 10. Time Resolution

### 10.1 `TrialPeriodResolver`
- المصدر: `config/nexusos.php` → `trial_days = 14`.
- الأنكور: وقت إصدار الـTrial.

### 10.2 `SubscriptionPeriodResolver`

```php
public function resolve(
    Plan $plan,
    CarbonImmutable $anchor,
): ?CarbonImmutable
```

- `month` → `addMonthsNoOverflow(billing_period_count)`
- `year` → `addYearsNoOverflow(billing_period_count)`
- `lifetime` → `null`
- يرفض وحدة أو عددًا غير مدعومين عبر
  `InvalidPlanBillingPeriodException`.

### 10.3 `GracePeriodResolver`

```php
public function resolve(
    Plan $plan,
    CarbonImmutable $anchor,
): CarbonImmutable
```

- المصدر (v1): `config/nexusos.php` →
  `tenant_license.grace_period_days = 7`.
- المدة عالمية حاليًا وليست per-plan؛ وجود `$plan` في العقد يحافظ على
  قابلية التوسع دون كسر الـAPI.
- **الأنكور دائمًا `license.expires_at`، وليس وقت تشغيل الـJob** —
  هذا يمنع تأخر الـScheduler من منح مهلة إضافية غير مستحقة (FD-006).

### 10.4 Anchor Rules (جدول موحّد)

| Service / Path | Anchor | Reason |
|---|---|---|
| `RenewTenantLicense` (active) | `license.expires_at` القديم | حماية الأيام المدفوعة المتبقية |
| `RenewTenantLicense` (grace recovery) | `occurredAt` (وقت التجديد الفعلي) | Grace ليست فترة مدفوعة؛ لا أيام مجانية ضمنية |
| `EnterLicenseGracePeriod` | `license.expires_at` | منع مكافأة تأخر الـScheduler (FD-006) |
| `ActivateTenantLicense` | `occurredAt` (وقت التفعيل) | استحقاق مدفوع جديد يبدأ الآن |
| `ChangeTenantPlan` (مدة مختلفة) | `occurredAt` | اعتماد خطة جديدة = فترة جديدة |
| `StartTenantSubscription` | `occurredAt` | اشتراك جديد بالكامل |

---

## 11. Exception Architecture

الاستثناءات الفعلية في v1 عددها تسعة، ومقسمة حسب المسؤولية:

### 11.1 Invariant / Corrupt State

- `InvalidTenantLicenseStateException`

يُستخدم عند كسر مصفوفة الاتساق بين:
`status × plan type × starts_at × expires_at × grace_ends_at`.

### 11.2 Invalid Transition

- `InvalidTenantLicenseTransitionException`

يحتوي Factory Methods و`reasonCode` لمسارات الرفض، ومنها:

- `cannot_activate_from_status`
- `cannot_renew_from_status`
- `lifetime_cannot_be_renewed`
- `cannot_enter_grace_period_from_status`
- `lifetime_cannot_enter_grace_period`
- `grace_period_not_yet_eligible`
- `expiration_not_yet_eligible`
- `grace_expiration_not_yet_eligible`
- `active_must_enter_grace_before_expiration`
- `cannot_expire_from_status`
- `cannot_cancel_from_status`
- `cannot_change_plan_from_status`
- `plan_already_assigned`
- `lifetime_to_finite_plan_change_not_allowed`
- `target_plan_inactive`

### 11.3 Temporal Past-Due

- `TenantLicensePastDueException`

مخصص للمواعيد الحرجة التي فاتت:

- `activationPastDue`
- `renewalPastDue`
- `graceRecoveryPastDue`

### 11.4 Input Validation

- `InvalidCancellationReasonException`

يرفض سبب الإلغاء الفارغ أو الذي يتجاوز 1000 حرف بعد `trim`.

### 11.5 Duration / Billing Configuration

- `InvalidLicenseDurationConfigurationException`
- `InvalidPlanBillingPeriodException`

### 11.6 Creation Eligibility / Availability

- `CurrentTenantLicenseAlreadyExistsException`
- `TrialAlreadyConsumedException`
- `PlanNotAvailableForLicenseException`

---

## 12. Domain Services

لكل خدمة: `handle()` signature، ترتيب الأقفال، الحالات المقبولة/المرفوضة، الفرق الجوهري عن أقرب خدمة مشابهة.

### 12.1 `IssueTrialLicense`
```php
handle(
    string $tenantId,
    string $planId,
    ?string $actorUserId = null,
    ?string $requestId = null,
): IssueTrialLicenseResult
```
- الأهلية: لا توجد `CURRENT_LICENSE`، ولا يوجد أي سجل تاريخي بـ`license_origin = trial` (Trial واحدة فقط في حياة الـTenant).
- `status = trial`, `license_origin = trial`.
- تستدعي `SyncTenantModulesFromPlanOperation` دائمًا (Trial = Operational License فعليًا).

### 12.2 `ActivateTenantLicense`
```php
handle(
    string $tenantId,
    ?string $actorUserId = null,
    ?string $requestId = null,
): ActivateTenantLicenseResult
```
- الانتقال الوحيد: `trial → active`.
- **لا تستقبل `planId` جديدًا** — الخطة ثابتة (تغيير خطة الـTrial غير مدعوم في v1، انظر FD-016).
- `plan.is_active` **غير مشترط** (Grandfathering — استمرار على ارتباط قائم وليس بيعًا جديدًا، FD-004).
- إعادة المحاولة بعد النجاح: **مرفوضة** (`InvalidTenantLicenseTransitionException`)، ليست idempotent.

### 12.3 `RenewTenantLicense`
```php
handle(
    string $tenantId,
    ?string $actorUserId = null,
    ?string $requestId = null,
): RenewTenantLicenseResult
```
- مسارين (state-dependent branches، نفس Use Case): `active → active` (early)، `grace_period → active` (recovery).
- Anchor مختلف حسب المسار (§10.4).
- **حالة حرجة:** `active AND expires_at <= now` → ترفض بـ `TenantLicensePastDueException::renewalPastDue` (وليست "تجديد مبكر عادي" — الرخصة متأخرة ولم تنتقل لـGrace بعد، FD-007).
- ليست idempotent — كل استدعاء ناجح = عملية مالية جديدة محتملة.

### 12.4 `EnterLicenseGracePeriod`
```php
handle(
    string $tenantId,
    ?string $actorUserId = null,
    ?string $requestId = null,
): EnterLicenseGracePeriodResult
```
- الانتقال الوحيد: `active → grace_period`. شرط: `expires_at <= now`.
- `grace_ends_at = GracePeriodResolver(anchor: expires_at)` — **قد يكون في الماضي عمدًا** (Catch-up scenario)، وهذا مقبول وليس خطأً (FD-006).
- تستدعي `SyncTenantModulesFromPlanOperation` **دائمًا** (ليس لأن Grace تغيّر الاستحقاقات، بل لإعادة فرضها وكشف أي Drift — FD-008).
- ليست idempotent.

### 12.5 `ExpireTenantLicense`
```php
handle(
    string $tenantId,
    string $tenantLicenseId,
    ?string $actorUserId = null,
    ?string $requestId = null,
): ExpireTenantLicenseResult
```
- مسارات: `trial → expired`, `grace_period → expired`. **`active → expired` مباشرة مرفوضة** (يجب المرور عبر Grace أولًا).
- **الخدمة الوحيدة الـIdempotent في الدومين بأكمله**: `expired → expired` = `changed: false`, بدون Mutation/Audit/Module operation (FD-002).
  - الـIdempotency محصورة تمامًا بهذه الحالة النهائية المتطابقة؛ لا تُستخدم لإصلاح أي عدم اتساق (مثل `license=expired` مع `tenant=active` بالخطأ) — هذا يعالَج لاحقًا عبر `ReconcileTenantLicenseState` (خدمة مستقبلية منفصلة، §17).
- تستدعي `RevokePlanModulesFromTenantOperation` (`trigger = license_expiration`).
- تعلّق `Tenant` (`active → suspended`) فقط إذا كان `active` فعلًا؛ لا Audit وهمي إذا كان معلّقًا مسبقًا.

### 12.6 `CancelTenantLicense`
```php
handle(
    string $tenantId,
    string $tenantLicenseId,
    string $reason,
    ?string $actorUserId = null,
    ?string $requestId = null,
): CancelTenantLicenseResult
```
- الحالات المقبولة: `trial`, `active`, `grace_period` — **بدون أي شرط زمني** (قرار إرادي فوري، بعكس `ExpireTenantLicense`).
- `reason` **إلزامي دائمًا** (بعد `trim`، 1-1000 حرف)؛ يُخزَّن حصرًا في `tenant_license.cancelled.metadata.reason` (لا يُكرَّر في Audit الوحدات).
- تستدعي `RevokePlanModulesFromTenantOperation` (`trigger = license_cancellation`).
- `actorUserId`: قيمة Attribution موثوقة من الطبقة المستدعية؛ **الخدمة لا تتحقق من وجودها أو صلاحيتها**.

### 12.7 `ChangeTenantPlan`
```php
handle(
    string $tenantId,
    string $tenantLicenseId,
    string $newPlanId,
    ?string $actorUserId = null,
    ?string $requestId = null,
): ChangeTenantPlanResult
```
- **الحالة المقبولة الوحيدة: `active`** (تراجع صريح عن قرار أولي كان يشمل `trial` — FD-016).
- ترتيب الفحص الحرج (بنيوي قبل تجاري):
  1. نفس الخطة الحالية؟ → `planAlreadyAssigned` (حتى لو الخطة نفسها inactive).
  2. `lifetime → finite`؟ → `lifetimeToFinitePlanChangeNotAllowed` (حتى لو الهدف inactive — بنيوي دائمًا يسبق تجاري).
  3. الخطة الهدف `is_active`؟ → `targetPlanInactive`.
- سلوك الفترة يعتمد على تطابق `billing_period_unit + billing_period_count` (وليس `unit` وحدها — `month/1` ≠ `month/3`):
  - **نفس المدة**: `starts_at`/`expires_at`/`grace_ends_at` بلا تغيير.
  - **مدة مختلفة** (بما فيها `finite → lifetime`): إعادة ضبط كاملة من `occurredAt`.
- تستدعي `SyncTenantModulesFromPlanOperation` دائمًا بالخطة الجديدة.

### 12.8 `StartTenantSubscription`
```php
handle(
    string $tenantId,
    string $planId,
    ?string $actorUserId = null,
    ?string $requestId = null,
): StartTenantSubscriptionResult
```
- الأهلية: غياب `CURRENT_LICENSE` فقط — **وليس** غياب أي سجل تاريخي (الفرق الجوهري عن `IssueTrialLicense`؛ رخص `expired`/`cancelled` سابقة لا تمنع، FD-015).
- `license_origin = subscription`, `status = active` مباشرة (لا Trial).
- `plan.is_active` مشترط (اعتماد خطة جديدة، وليس استمرارًا).

---

## 13. Cross-Domain Operations

### 13.1 `SyncTenantModulesFromPlanOperation`
- **Internal Domain Operation** فقط في سياق TenantLicense (لا تفتح Transaction، لا تولّد `now()`/`requestId` داخليًا — تستقبلهما من الخارج).
- استُخرجت من `SyncTenantModulesFromPlan` (الـPublic Service الأصلية في `TenantModule Domain`) بعد Characterization Coverage كاملة أثبتت الحفاظ على السلوك، وملكية الـTransaction، وتمرير الوقت والـrequest ID.
- تُستخدم من: `IssueTrialLicense`, `ActivateTenantLicense`, `RenewTenantLicense`, `EnterLicenseGracePeriod`, `ChangeTenantPlan`, `StartTenantSubscription`.
- تكتب Audit دائمًا، حتى عند `unchanged` بالكامل (يوثّق أن دورة المزامنة نُفِّذت فعليًا).

### 13.2 `RevokePlanModulesFromTenantOperation`
```php
execute(
    string $tenantId, string $licenseId, string $planId,
    string $trigger, CarbonImmutable $occurredAt,
    ?string $actorUserId, string $requestId,
): RevokePlanModulesFromTenantResult
```
- `trigger` **إلزامي ومقيّد** بثابتين فقط (يُرفض أي قيمة أخرى):
  ```php
  const TRIGGER_LICENSE_EXPIRATION = 'license_expiration';
  const TRIGGER_LICENSE_CANCELLATION = 'license_cancellation';
  ```
- Audit event: `tenant_module.plan_entitlement_revoked` (⚠ ليس `tenant_module.revoked_from_plan` كما كان في العقد الأولي قبل تصحيحه — FD-013).
- تستهدف حصرًا: `source = 'plan' AND status = 'enabled'`. الحالة النهائية: `disabled` فقط (لا حالات جديدة).
- **لا `Public Service` مقابلة في v1** — لا Caller مستقل مُثبت (يُبنى فقط عند ظهور حاجة حقيقية).

### 13.3 Service Dependency Diagram

```
IssueTrialLicense
      │
      ▼
SyncTenantModulesFromPlanOperation

ActivateTenantLicense
      │
      ▼
SyncTenantModulesFromPlanOperation

RenewTenantLicense
      │
      ▼
SyncTenantModulesFromPlanOperation

EnterLicenseGracePeriod
      │
      ▼
SyncTenantModulesFromPlanOperation

ChangeTenantPlan
      │
      ▼
SyncTenantModulesFromPlanOperation

StartTenantSubscription
      │
      ▼
SyncTenantModulesFromPlanOperation

ExpireTenantLicense
      │
      ▼
RevokePlanModulesFromTenantOperation

CancelTenantLicense
      │
      ▼
RevokePlanModulesFromTenantOperation
```

---

## 14. Audit Contract

- **كل صف Audit** يحدد كيانًا أساسيًا واحدًا (`entity_type` + `entity_id`).
- **عملية واحدة قد تُنتج عدة صفوف** (مثال: `ExpireTenantLicense` قد تكتب `tenant_license.expired` + `tenant.suspended_due_to_license_expiration` + صفوف `tenant_module.plan_entitlement_revoked` — كلها Atomic في نفس Transaction، بنفس `requestId`/`occurredAt`).
- **لا صف Audit لكيان لم تتغير حالته فعليًا** (مثال: لا `tenant.suspended_...` إذا كان الـTenant معلّقًا مسبقًا).
- **لا تكرار بيانات** عبر الصفوف: `reason` في `CancelTenantLicense` يبقى فقط في Audit الرخصة، لا يُنسخ لصفوف الوحدات.
- **`requestId`/`occurredAt` موحّدان** عبر كل صفوف الـAudit الناتجة عن نفس استدعاء الخدمة.
- **`actor_user_id`**: قيمة حقيقية عند فاعل بشري، `null` عند إجراء نظامي — لا يُخترع System User أو Sentinel وهمي أبدًا.

---

### 14.1 Audit Event Catalog

| Event | Primary Entity | Owner |
|---|---|---|
| `tenant_license.trial_issued` | `TenantLicense` | `IssueTrialLicense` |
| `tenant_license.activated` | `TenantLicense` | `ActivateTenantLicense` |
| `tenant_license.renewed` | `TenantLicense` | `RenewTenantLicense` |
| `tenant_license.grace_period_entered` | `TenantLicense` | `EnterLicenseGracePeriod` |
| `tenant_license.expired` | `TenantLicense` | `ExpireTenantLicense` |
| `tenant_license.cancelled` | `TenantLicense` | `CancelTenantLicense` |
| `tenant_license.plan_changed` | `TenantLicense` | `ChangeTenantPlan` |
| `tenant_license.subscription_started` | `TenantLicense` | `StartTenantSubscription` |
| `tenant.suspended_due_to_license_expiration` | `Tenant` | `ExpireTenantLicense` |
| `tenant.suspended_due_to_license_cancellation` | `Tenant` | `CancelTenantLicense` |
| `tenant_module.synced_from_plan` | `Tenant` | `SyncTenantModulesFromPlanOperation` |
| `tenant_module.plan_entitlement_revoked` | `Tenant` | `RevokePlanModulesFromTenantOperation` |

---

## 15. Locking & Transactions

- **Transactional Composition**: الـ Outer Service (كل خدمة من الثماني) هي المالك الوحيد للـTransaction. الـOperations المُركَّبة (§13) لا تفتح/تغلق Transaction خاصة بها.
- ترتيب الأقفال ثابت: `Tenant → TenantLicense → TenantModule rows (ORDER BY id)`.
- لا تمرير `Connection`/`Transaction` objects كمعاملات عبر عقد أي Service.
- الـRollback الحقيقي (وليس Mock) يُختبر عبر فشل PostgreSQL فعلي داخل نفس الـTransaction (تطبيق أفضل من الاعتماد على FK اصطناعي أو Mock هش).

---

## 16. Idempotency Matrix

| Service | Retry Allowed | Behaviour | Basis |
|---|---|---|---|
| `IssueTrialLicense` | ❌ | Exception | One-time trial policy |
| `ActivateTenantLicense` | ❌ | Exception | Single-transition ownership |
| `RenewTenantLicense` | ❌ | Exception | Each call = potential financial transaction |
| `EnterLicenseGracePeriod` | ❌ | Exception | Single-transition ownership; retries are stale candidates |
| `ExpireTenantLicense` | ✅ | Snapshot only (`expired → expired`) | Retry-safe scheduler |
| `CancelTenantLicense` | ❌ | Exception | Terminal state, irreversible decision |
| `ChangeTenantPlan` | ❌ | Exception | Distinct commercial decision each time |
| `StartTenantSubscription` | ❌ | Exception | New subscription record each time |

> **ملاحظة حرجة:** `ExpireTenantLicense` هي الخدمة الوحيدة الـIdempotent، ومحصورة تمامًا بمسار `expired → expired`. هذا ليس تفويضًا لإصلاح أي عدم اتساق آخر (§12.5).

---

## 17. Testing Architecture

- **Policy Layer**: `TenantLicenseTransitionRules` — 39 اختبار وحدة مستقل (بدون DB/Transactions/Locks).
- **PostgreSQL Testing Environment**: `.env.testing` + `phpunit.xml` معزولان، الاختبارات تعمل على PostgreSQL حقيقي (يطابق الإنتاج)، وليس SQLite.
- **Characterization Coverage**: اختبارات Feature مستقلة ثبّتت سلوك `SyncTenantModulesFromPlan` و`SyncTenantModulesFromPlanOperation` قبل وبعد الاستخراج، بما في ذلك ملكية الـTransaction والوقت والـrequest ID.
- **إجمالي**: 221 اختبارًا، 706 Assertions، 0 Failures (حتى commit `bb1c35d`).
- **مؤجل**: Concurrency Test Suite حقيقي (اتصالان متزامنان لكشف سباقات فعلية على قفل الـTenant والـPartial Unique Index) — يُبنى في `tests/Concurrency/TenantLicense/` كمجموعة مستقلة.

---

## 18. Deferred Work

| Item | Reason for Deferral |
|---|---|
| `ChangeTrialPlan` | تغيير خطة الـTrial قبل الدفع غير مدعوم في v1؛ يحتاج قرارات إضافية (إعادة مدة الـTrial؟ الانتقال لـLifetime؟ منع إساءة الاستخدام؟) |
| `ReconcileTenantLicenseState` | خدمة مستقبلية لإصلاح عدم اتساق تاريخي (مثال: `license=expired` + `tenant=active`)؛ **لا تُخلط مع** `ReconcileTenantLicenseExpiry`، وهو Orchestrator زمني مخطط للـCatch-up بين Grace وExpiration |
| `ReplaceLifetimeLicense` / License Repair Tool | لـ`lifetime → finite` — يحتاج صلاحية إدارية عليا وسبب إلزامي وAudit كامل، مرفوض تمامًا عبر `ChangeTenantPlan` العادية |
| Per-plan grace duration | `GracePeriodResolver` جاهز للتوسع (يستقبل `$plan`)، لكن السياسة الحالية عالمية عبر Config فقط |
| Concurrency Test Suite | يحتاج بنية اختبارية مختلفة (اتصالان مستقلان)؛ مُجدولة بعد إغلاق TenantLicense Domain |

---

## 19. Frozen Decisions

```
FD-001
CancelTenantLicense requires a mandatory `reason` (trimmed, 1-1000 chars),
stored only in the TenantLicense audit metadata.
Reason: Cancellation is an irreversible commercial decision requiring
documented justification.

------------------------------------

FD-002
ExpireTenantLicense is the only idempotent service in the domain.
Idempotency is strictly limited to `expired → expired`.
Reason: Retry-safe scheduler (LicenseExpiryJob may re-dispatch safely).

------------------------------------

FD-003
IssueTrialLicense rejects any historical trial license record,
regardless of its status.
Reason: One-lifetime-trial policy per Tenant.

------------------------------------

FD-004
ActivateTenantLicense does not require `plan.is_active = true`.
Reason: Grandfathering — continuing an existing commercial relationship,
not adopting a new plan.

------------------------------------

FD-005
ChangeTenantPlan requires `plan.is_active = true` for the target plan.
Reason: This is New Plan Adoption, the opposite intent of FD-004.

------------------------------------

FD-006
GracePeriodResolver anchors grace_ends_at to license.expires_at,
never to job/scheduler execution time.
Reason: Scheduler delay must never grant additional entitlement time.

------------------------------------

FD-007
RenewTenantLicense rejects `active` licenses where
`expires_at <= renewal timestamp`, via TenantLicensePastDueException,
rather than treating it as early renewal.
Reason: Prevents implicitly granting free overdue days through a
stale `active` status that should have transitioned to grace_period.

------------------------------------

FD-008
EnterLicenseGracePeriod always invokes SyncTenantModulesFromPlanOperation,
even though grace_period does not change module entitlements.
Reason: Reinforces correct entitlement state and detects drift at an
important lifecycle transition point.

------------------------------------

FD-009
ExpireTenantLicense's idempotent path does not repair inconsistent
side effects (e.g., an already-expired license whose Tenant is still
`active`). Repair belongs to a separate future service
(ReconcileTenantLicenseState), never to a retry no-op.

------------------------------------

FD-010
RevokePlanModulesFromTenantOperation exists as an Internal Domain
Operation only. No corresponding Public Service is built in v1
because no standalone caller has been proven to need one.

------------------------------------

FD-011
ChangeTenantPlan checks structural rules (same-plan identity,
lifetime→finite prohibition) before commercial rules (target plan
active/inactive) — even when both would independently cause rejection.
Reason: Structural invariants must always take precedence over
commercial state in the reported failure reason.

------------------------------------

FD-012
ChangeTenantPlan compares billing_period_unit AND billing_period_count
together, not unit alone, to decide whether a period restarts.
Reason: month/1 and month/3 share a unit but represent different
commercial durations.

------------------------------------

FD-013
RevokePlanModulesFromTenantOperation's audit event name is
tenant_module.plan_entitlement_revoked (not the originally implemented
tenant_module.revoked_from_plan), and its trigger metadata is a
required, constrained parameter (not a hardcoded internal value).
Reason: The operation must be genuinely reusable by both
ExpireTenantLicense and CancelTenantLicense without accidental
trigger leakage via copy-paste.

------------------------------------

FD-014
The exception used for "same plan already assigned" is
InvalidTenantLicenseTransitionException::planAlreadyAssigned()
(reasonCode: plan_already_assigned) — not a standalone
TenantLicenseAlreadyUsesPlanException class as originally proposed.
Reason: Implementation consolidated it under the existing
three-exception architecture rather than introducing a fourth class.

------------------------------------

FD-015
StartTenantSubscription checks only for the absence of a
CURRENT_LICENSE (trial/active/grace_period), not the absence of any
historical license record — unlike IssueTrialLicense, which rejects
any historical trial regardless of status.
The exception used is CurrentTenantLicenseAlreadyExistsException
(not TenantAlreadyHasCurrentLicenseException as originally proposed).
Reason: Enables Enterprise/Direct Sales onboarding after a prior
expired or cancelled license, while IssueTrialLicense enforces a
strict one-time trial policy.

------------------------------------

FD-016
ChangeTenantPlan accepts only `active` licenses in v1 (trial is
rejected). This reverses an earlier design decision that had
permitted `trial` as well.
Reason: ActivateTenantLicense does not accept a new planId, so
changing a trial's plan has no supporting service in v1. Trial plan
changes require a dedicated future service (ChangeTrialPlan, §18)
with unresolved open questions (trial duration reset? lifetime
transition? abuse prevention?).
```

---

## 20. Domain Metrics

```
Lifecycle Services:        8
Time Resolvers:            3
Internal Operations:       2
Transition Policy:         1
Result DTOs:               8
Exception Classes:         9
Frozen Decisions:         16
Tests:                    221
Assertions:               706
Test Failures:              0
```

---

## 21. Glossary

| Term | Definition |
|---|---|
| **Current License** | رخصة بحالة ضمن `CURRENT_STATUSES` (`trial`, `active`, `grace_period`) — أي رخصة "حية" تشغيليًا للـTenant |
| **Lifetime** | خطة/رخصة بدون تاريخ انتهاء (`expires_at = null`)؛ لا تدخل Grace ولا تنتهي زمنيًا |
| **Anchor** | نقطة البداية الزمنية التي يُحسب منها تاريخ انتهاء جديد (قد تكون `occurredAt` أو `expires_at` القديم، حسب الخدمة والمسار) |
| **Grace (Period)** | فترة سماح تشغيلية بعد انتهاء الفترة المدفوعة، تسمح باستمرار الاستخدام ريثما يُحصَّل الدفع |
| **Trial** | رخصة تجريبية أولى وحيدة لكل Tenant، تُعامَل كرخصة تشغيلية كاملة (وليست "حالة انتظار") |
| **Grandfathering** | استمرار استحقاق قائم على خطة أصبحت `is_active = false`، دون اعتباره اعتمادًا جديدًا لتلك الخطة |
| **Catch-up (Scenario)** | حالة يكتشف فيها نظام مجدول (Job) انتقال حالة متأخرًا، فيُنفَّذ الانتقال الصحيح بأثر رجعي دون منح وقت إضافي |
| **Idempotent** | استدعاء متكرر للخدمة على نفس الحالة النهائية لا يُحدث تغييرًا إضافيًا ولا يُعامَل كخطأ (مقصور على `ExpireTenantLicense` فقط في هذا الدومين) |
| **Recovery** | مسار `grace_period → active` ضمن `RenewTenantLicense` — استعادة الرخصة من فترة السماح إلى النشاط الكامل |
| **Terminal (State)** | حالة نهائية لا رجوع منها ضمن نفس السجل (`expired`, `cancelled`) — العودة تتطلب سجل رخصة جديد بالكامل |
| **Drift** | تباين بين الحالة الفعلية لموديولات الـTenant (`tenant_modules`) والحالة المفترضة وفق الخطة الحالية؛ تُصحَّح عبر إعادة المزامنة |
| **Trigger** | سياق سبب استدعاء عملية سحب الاستحقاقات (`license_expiration` أو `license_cancellation`) — يُخزَّن في الـAudit metadata، وليس اسم الحدث نفسه |
| **Internal Domain Operation** | مكوّن يُنفّذ Mutation داخل Transaction مملوكة من الخارج، دون أن يفتح أو يغلق Transaction خاصة به |

---

## 22. Document Maintenance Rule

أي تعديل مستقبلي على عقد الدومين يجب أن يحدث بالترتيب التالي:

1. تعديل/إضافة الاختبار الذي يثبت العقد الجديد.
2. تعديل التنفيذ حتى تعود المجموعة إلى Green.
3. تحديث هذه الوثيقة لتعكس التنفيذ.
4. إضافة Frozen Decision جديد إذا كان التغيير يحمل Rationale طويل العمر.
5. إنشاء Commit وTag جديدين عند تجميد نسخة جديدة.

لا تُعدّل هذه الوثيقة لتصف سلوكًا غير موجود في الكود، ولا يُدمج تغيير
سلوكي في الكود دون تحديث الوثيقة قبل إغلاق المرحلة.

---

## 23. Version History

```
v1 — 13 يوليو 2026
Initial implementation. Eight lifecycle services, policy layer,
two cross-domain operations, full test suite (221 tests).
Tag: tenant-license-domain-v1 — Commit: bb1c35d

Future versions append here.
```

---

## 24. Implementation Summary

```
✅ Schema (plans, tenant_licenses) + CHECK Constraints
✅ Models (Plan, TenantLicense)
✅ Configuration Layer (config/nexusos.php)
✅ Resolvers (Trial, Subscription, Grace) + Resolver Tests
✅ Exception Architecture (9 classes across invariant, transition, temporal, input, configuration, and eligibility concerns)
✅ TenantLicenseTransitionRules (Policy Layer) + 39 Unit Tests
✅ DTOs (8 Result classes, one per service)
✅ TenantModule Domain refactor:
     SyncTenantModulesFromPlanOperation extracted (behavior-preserving)
✅ RevokePlanModulesFromTenantOperation (generalized trigger contract)
✅ IssueTrialLicense + tests
✅ ActivateTenantLicense + tests
✅ RenewTenantLicense + tests
✅ EnterLicenseGracePeriod + tests
✅ ExpireTenantLicense + tests
✅ CancelTenantLicense + tests
✅ ChangeTenantPlan + tests
✅ StartTenantSubscription + tests

Total: 221 tests, 706 assertions, 0 failures
Git Tag: tenant-license-domain-v1
Commit: bb1c35d

⏳ Concurrency Test Suite (deferred, §17/§18)
⏳ Application Layer (Use Cases, Orchestrators — next phase)
```
