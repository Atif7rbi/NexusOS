# NexusOS — Architecture Principles

---

# Design Philosophy

NexusOS is built around one central belief:

> **Correctness > Convenience**

Every architectural decision should favor:

- Data integrity over developer convenience.
- Explicit behavior over hidden framework magic.
- Long-term maintainability over short-term shortcuts.
- Database guarantees over framework assumptions.

Version: **1.2**  
Frozen together with Git tag: **db-core-v1**


Revision History

- **v1.0**
  - Initial Architecture Principles
  - Database Core v1 Freeze

- **v1.1**
  - Added Design Philosophy
  - Added Explicit over Magic
  - Added Database Evolution Principle
  - Added Architecture Layers
  - Added Decision Flow
  - Added General Data Duplication Rule
  - Added Naming Examples
  - Added Governance Rule

- **v1.2**
  - Added cross references
  - Numbered architecture sections
  - Added Architecture Decision Records (ADR)




**Status:** Living Document — Source of Truth
**Scope:** Applies to every table, Model, and Service in NexusOS, present and future.

هذا الملف هو المرجع الأعلى لأي قرار معماري في NexusOS. أي جدول أو Model أو Service جديد يجب أن يُقاس على هذه المبادئ قبل أي نقاش من الصفر. الهدف: تحويل القرارات من "شعور" أو "ذاكرة محادثة" إلى قواعد هندسية مكتوبة وقابلة للرجوع إليها — للفريق، ولأي AI يعمل على المشروع مستقبلًا.

---

## 1) Database First Architecture

> Database is the last line of defense.

لا نعتمد على Laravel وحده لحماية سلامة البيانات. نستخدم دائمًا:

- CHECK Constraints
- Foreign Keys (بما فيها Composite Foreign Keys عند الحاجة)
- Partial Unique Indexes
- PostgreSQL Features (JSONB, Triggers, إلخ)
- `restrictOnDelete()` حيث يلزم منع الحذف الفعلي

القاعدة العملية: أي قرار سلامة بيانات حرج (Critical Data Integrity) يُفرض في PostgreSQL أولًا، وطبقة Laravel/Service تُعتبر خط دفاع ثانٍ وليس الوحيد.

---

## 2) Thin Model Principle

```text
Models contain:
- Relationships
- State Scopes (فلترة حالة فقط، بدون قرار)

Services contain:
- Business Decisions
- Validation
- Workflows
- أي منطق مركّب (Cloning, Syncing, Cross-entity checks)
```

مثال على الفرق:

```php
// ✅ مقبول داخل Model — فلترة حالة فقط
TenantUser::current()

// ❌ غير مقبول داخل Model — قرار أعمال
TenantUser::canAccessWorkspace()
```

لا نضع منطق تحقق بنيوي (Structural Guards) داخل `booted()`/`creating()` حتى لو بدا "حماية إضافية بسيطة" — التحقق يعيش في Service، والحماية النهائية تعيش في PostgreSQL (Composite FK / CHECK).

**سبب التبني:** يمنع تشتت منطق الأعمال بين عشرات الـ Models، ويجعل كل قدرة (Capability) لها مكان واحد معروف.

---

## 3) Service Ownership Principle

```text
Every business capability has one owner.
```

كل قدرة أعمال لها Service واحد مسؤول عنها حصريًا، ولا يوجد منطق موزّع بين Models أو Controllers:

```text
RoleService              → clone template, deprecate role, protect system roles
RolePermissionService     → assign / remove / sync permissions
PermissionService         → create/update/deprecate, enforce code prefix, validate actions
MembershipService (مقترح) → current membership, join/leave logic
TenantRoleAssignmentService → تحقق بنيوي عند إسناد دور (tenant_id consistency)
```

عند إضافة قدرة جديدة، أول سؤال: "أي Service يملك هذه المسؤولية؟" — لا نضيفها كدالة عائمة في Model أو Controller.

---

## 4) State vs Event Principle

```text
Current State  → lives inside domain tables
Historical Events → live only in audit_logs
```

الجداول التشغيلية (`tenant_users`, `tenant_user_roles`, `role_permissions`, ...) تمثّل **الحالة الحالية فقط**. أي "من فعل ماذا ومتى ولماذا" هو **حدث تاريخي** ومكانه الوحيد `audit_logs` (Append-Only، Immutable، بدون Foreign Keys بالتصميم، محمي بـ Triggers من التعديل/الحذف).

تطبيقات هذا المبدأ حتى الآن:

- `tenant_user_roles`: لا `status` — وجود الصف = إسناد قائم، حذفه = إلغاء. التاريخ في `audit_logs`.
- `role_permissions`: لا `granted_by`/`granted_at`/`timestamps` — لأن التعديل يتم غالبًا كـ Sync جماعي (حذف/إضافة عشرات الصفوف دفعة واحدة)، وقيمة "آخر من عدّل" غير ذات معنى لصف منفرد. الحقيقة الكاملة في `audit_logs`.
- `tenant_user_roles.assigned_by`/`assigned_at` **استثناء مقصود**: أُبقي عليها لأنها تمثل "من أسند حاليًا" كحالة قائمة (Assignment فردي له معنى كصف واحد)، بخلاف `role_permissions` (Grant ضمن مجموعة).

**القاعدة الفارقة:** إسناد فردي (Assignment) له معنى كـ "آخر حالة" → يجوز الاحتفاظ بعمود الحالة الأخيرة. تغيير جماعي (Sync) لا معنى فيه لـ"آخر من عدّل" على مستوى الصف المنفرد → كل شيء في `audit_logs`.

---

## 5) Tenant Duplication Rule

```text
Duplicate tenant_id only when BOTH referenced entities
are tenant-scoped and database-level tenant isolation
requires a composite foreign key.

Do NOT duplicate tenant_id merely for consistency.
Duplicate only when it adds real security guarantees.
```

تطبيقات:

```text
✅ tenant_user_roles → tenant_user (tenant-scoped) ↔ role (tenant-scoped)
   → يُكرر tenant_id + Composite FK يمنع خلط الأدوار بين المستأجرين

❌ role_permissions → role (tenant-scoped) ↔ permission (global)
   → لا يُكرر tenant_id، لأنه لا يوجد طرف Tenant ثانٍ يمكن يحدث معه خلط
```

قبل تكرار `tenant_id` في أي جدول مستقبلي، اسأل: **هل الطرفان معًا Tenant-scoped، وهل التكرار يضيف ضمان أمني حقيقي (Composite FK)؟** لو الجواب لا لأي شرط، لا تُكرره.

---

## 6) Template Baseline Rule

```text
Template cloning copies the baseline.
Identity restoration never restores history.
```

هذان مبدآن يبدوان متناقضين ظاهريًا لكنهما نفس الفلسفة مطبّقة على سياقين مختلفين تمامًا:

```text
Clone Role Template → Tenant Role
  → ينسخ role_permissions معه (Baseline مقصود، نقطة انطلاق قابلة للتعديل)

Rehire User (العودة بعد tenant_users.status = removed)
  → لا يرث الأدوار القديمة، يبدأ بدون Roles
  → (خيار يدوي مستقبلي: Rehire → [ ] Copy Previous Roles)
```

الفرق الجوهري: **Template** مصمم أصلًا ليكون نقطة بداية عامة تُنسخ (قصده التوفير). **Identity/Membership** الفردية لا تحمل افتراض وراثة تلقائية أبدًا — كل عضوية جديدة نظيفة أمنيًا حتى تُبنى صراحة.

بعد النسخ من Template، الدور الناتج **مستقل تمامًا** — أي تعديل لاحق على القالب لا يُزامَن تلقائيًا مع النسخ.

---

## 7) Status-Driven Lifecycle (No Hard Deletes)

لا حذف فعلي للكيانات المرجعية (`roles`, `permissions`, `modules`, `plans`, ...). بدلًا من ذلك:

```text
is_active   boolean
deprecated_at   timestampTz nullable
```

`is_active` و`deprecated_at` مرتبطان لكن **مستقلان بنيويًا** (لا CHECK يفرض تزامنهما، الفرض دايمًا عبر Service):

```text
is_active      = هل الكيان قابل للاستخدام الآن؟
deprecated_at  = متى تم تقاعده/إيقاف اعتماده؟
```

عند Deprecate، **لا نحذف الإسنادات القائمة** (مثلًا `role_permissions` لا تُحذف عند تقاعد Permission). بدلًا من ذلك، الـ **Runtime Resolver/Permission Engine** هو من يتجاهل أي كيان `is_active = false` أو `deprecated_at` معبّى وقت التحقق الفعلي (Runtime)، لا وقت التخزين.

---

## 8) Identity Model

```text
One Human → One Global User (users)
         → Membership (tenant_users)
         → Roles (tenant_user_roles)
         → Permissions
```

- **Login:** Global. **Session:** Tenant Scoped. لا يوجد Active Tenant داخل `users`.
- **Global Disable** (`users.is_active`): يعطل المستخدم في جميع الشركات.
- **Tenant Disable** (`tenant_users.status`): يعطل المستخدم داخل Tenant واحد فقط.
- **Leaving Company:** `tenant_users.status → removed`. العودة تُنشئ **صف جديد**، وليس إعادة استخدام القديم.
- **Role Inheritance:** ممنوعة تلقائيًا دائمًا (راجع Template Baseline Rule أعلاه للاستثناء الوحيد المقصود: نسخ Template).

---

## 9) Explicit Relationships — No Hidden Shortcuts

لا نعتمد `belongsToMany` كمسار رسمي بين كيانات لها حالة أو تاريخ أو شرط أمني (مثل `Tenant ↔ User` عبر `tenant_users`، أو `TenantUser ↔ Role` عبر `tenant_user_roles`)، لأن الاختصار قد **يُخفي شرط فلترة حرج** (مثل `status`) ويُسهّل غلطة مستقبلية.

`belongsToMany` **يُقبل فقط كـ read-only convenience** حين لا يوجد خطر عزل أو حالة مخفية:

```text
✅ Role → permissions()      (read-only, لا خطر Cross-tenant, الكتابة عبر RolePermissionService)
✅ Permission → roles()      (نفس السبب)
❌ Tenant → users()          (يُخفي شرط status — العلاقة الرسمية: hasMany TenantUser)
❌ TenantUser → roles()      (يُخفي tenant_id isolation — الرسمية: hasMany TenantUserRole)
```

القاعدة الفارقة: هل الاختصار قد يُخفي شرط أمني/حالة يُنسى تطبيقه لاحقًا؟ لو نعم → ممنوع كمسار رسمي.

---

## 10) No Global Tenant Scope

> This rule is a practical application of the Explicit over Magic Principle (Section 13). (V1)

```text
No automatic tenant global scope in V1.
Tenant filtering must be explicit in Services/Repositories.
```

**السبب:**
- قد يُخفي أخطاء.
- يعقّد أوامر Admin/System Jobs (اللي تحتاج تتخطى حدود Tenant عمدًا).
- العزل الحرج مضمون أصلًا في PostgreSQL (Composite FK).
- في هذه المرحلة، نريد استعلامات صريحة وواضحة، لا سحرًا ضمنيًا.

لاحقًا يمكن إضافة `trait BelongsToTenant` كأداة مساعدة، لكن **بدون فرض Global Scope إجباري** في V1.

---

## 11) Naming Convention Standard

| النوع | الصيغة |
|---|---|
| Index | `idx_<table>_<columns>` |
| Unique Index | `uq_<table>_<purpose>` |
| CHECK Constraint | `<table>_<column>_check` أو `<table>_<purpose>_check` |
| FK | Laravel الافتراضي (يتبع معيارًا واضحًا أصلًا) |

التعليقات التقنية داخل الـ Migrations: **إنجليزية فقط** (توحيد الكود، حتى لو النقاش والتوثيق بالعربي).

---

## 12) Primary Keys — ULID Always

```text
Every entity has a stable identity.
```

`id ULID` يُعتمد دائمًا كمعيار عام، **حتى في الجداول اللي تبدو Pivot تقني بحت** (مثل `role_permissions`)، بدل Composite Primary Key، لأن:

- يمنح مرونة تطوير مستقبلية (إضافة أعمدة كـ `expires_at`/`condition`/`scope` بدون إعادة بناء المفاتيح).
- الاتساق عبر كل المشروع أهم من توفير عمود واحد في حالات نادرة.

الفرادة الفعلية (مثل منع تكرار `role_id + permission_id`) تُفرض عبر `UNIQUE(...)` منفصل، وليس عبر جعله Primary Key مركّب.

---

## Frozen Tables Reference (Database Core v1)

```text
Core:      tenants, plans, modules, plan_modules, tenant_licenses
Identity:  users, tenant_users
RBAC:      roles, permissions, role_permissions, tenant_user_roles
Licensing: tenant_modules
Audit:     audit_logs
```

Git Tag: `db-core-v1` — Frozen. تعديلات المخطط مسموحة فقط لإصلاح خطأ معماري مؤكد، أو عبر migrations جديدة. لا إعادة كتابة لـ migrations قائمة.

---

## Domain Layer Progress

```text
✅ Tenant / User / TenantUser / TenantUserRole / Role / Permission / RolePermission
⏳ TenantModule       ← التالي
⏳ AuditLog
```

---

هذا الملف يُحدَّث كلما استُخرج مبدأ معماري جديد أثناء تصميم أي جدول أو Model قادم. أي قرار مستقبلي يتعارض مع مبدأ هنا يجب أن يُناقَش صراحة كتعديل على المبدأ نفسه، لا كاستثناء صامت.


---

## 13. Explicit over Magic Principle

NexusOS intentionally avoids hidden framework behavior.

Prefer:

- Explicit queries
- Explicit tenant filtering
- Explicit service calls
- Explicit transactions

Avoid:

- Global scopes

> See Section 10 (No Global Tenant Scope) for a concrete application of this principle.
- Hidden `boot()` logic
- Model observers for business rules
- Implicit defaults on critical fields
- Hidden side effects

---

## 14. Database Evolution Principle

Released migrations are immutable.

- Never rewrite released migrations.
- Fixes are introduced only through new migrations.
- Migration history is part of the system history.

---

## 15. Architecture Layers

```
Presentation
    ↓
Controllers
    ↓
Services
    ↓
Repositories (future)
    ↓
Models
    ↓
PostgreSQL
```

Business decisions belong to **Services**.

Data integrity belongs to **PostgreSQL**.

---

## 16. Decision Flow

Before introducing any new feature ask:

```
Need new feature?
      ↓
Need new table?
      ↓
Need new Service?
      ↓
Need Audit?
      ↓
Need CHECK constraint?
      ↓
Need Foreign Key?
      ↓
Need Composite Foreign Key?
      ↓
Need Index?
      ↓
Need Tests?
```

---

## 17. What NEVER Goes into Models

Models must never contain:

- Authorization decisions
- Permission evaluation
- Workflow orchestration
- Transactions
- Notifications
- Audit writing
- Cross-entity validation
- External API calls
- Synchronization logic
- Scheduling logic

---

## 18. General Data Duplication Rule

Duplicate data only when at least one of the following is true:

- Security
- Performance
- Historical snapshots
- Referential constraints

Otherwise, normalize.

---

## 19. Naming Examples

| Item | Pattern |
|------|---------|
| Tables | `tenant_users`, `tenant_modules` |
| Models | `TenantUser`, `Role`, `Permission` |
| Services | `RoleService`, `MembershipService` |
| Indexes | `idx_table_columns` |
| Unique | `uq_table_purpose` |
| CHECK | `table_column_check` |
| Triggers | `trg_table_action` |
| Functions | `fn_table_action` |

---

## 20. Future Architecture Roadmap

Upcoming layers after Database Core v1:

- TenantModule Domain
- AuditLog Domain
- Authentication
- Permission Resolver
- Authorization Layer
- Policies
- Repositories
- Domain Events
- Jobs
- Notifications
- API Layer

---

## 21. Governance Rule

A new architectural decision is not considered officially adopted until it is documented in this file.

This document is the architectural Single Source of Truth for NexusOS.
