# Laravel Scaffold — Spec-Driven API Scaffolding for Laravel

> Stop rewriting CRUD. Define your backend once, generate everything else.

**Laravel Scaffold** is a developer productivity tool that generates **production-ready Laravel APIs** from either:
- an **existing database**, or
- a **simple human-readable spec file**

It is designed for developers and teams who build **many backends**, not demos.

✅ Used in production  
✅ Spec-driven & DB-driven  
✅ Clean architecture (Services, Repositories, Requests)  
✅ Safe, fast, and extensible  

---

## Why This Exists

If you’ve built more than a few Laravel applications, you already know the pattern:

- Create models  
- Write migrations  
- Build repositories  
- Add services  
- Repeat validation rules  
- Repeat again on the next project  

This tool exists to **eliminate that repetition**.

Instead of writing structure over and over, you define **intent once**, and let the tool generate consistent, maintainable code.

---

## Installation

```bash
composer require ahertl/laravel-scaffold
```

---

## Two Ways to Use It

### 1️⃣ Database-First Scaffolding (Legacy or Existing Projects)

Generate a full CRUD API directly from an existing table:

```bash
php artisan laravel:scaffold User --table=users --routes
```

This generates:
- Model
- Repository
- Service
- Controller
- Optional routes

Perfect for:
- legacy systems
- modernizing old databases
- inherited projects

---

### 2️⃣ Spec-Driven Scaffolding (Recommended)

Define your backend using a **simple text spec**:

```text
user:
- name string required
- email string required unique
- password string hidden

book:
- title string
- isbn string unique
- authorId number
```

Generate everything:

```bash
php artisan laravel:scaffold --spec=api.spec --migration
```

### Rich Spec Syntax

The original simple syntax still works, but richer migration-focused specs are also supported:

```text
school table:schools softDeletes:
- schoolName string:150 required
- contactEmail string:191 required unique
- contactPhone string:30 nullable index
- isActive boolean default:true

student table:students softDeletes:
- schoolId foreignId:schools required cascadeOnDelete
- admissionNumber string:60 required unique
- firstName string:100 required
- lastName string:100 required
- middleName string:100 nullable
- gender enum:male,female nullable
- meta json nullable

payment table:payments:
- schoolId foreignId:schools required cascadeOnDelete
- parentId foreignId:parents required cascadeOnDelete
- amount decimal:12,2 required
- status enum:pending,paid,failed default:pending index
- paidAt datetime nullable
```

Entity directives can add application intent beyond plain CRUD:

```text
resultPublication table:result_publications:
@route result-publications
@search studentName admissionNumber
@commands PublishResult SendResultToParent
@events ResultPublished ResultSentToParent
@state Draft Published Sent
@transition Draft Published PublishResult ResultPublished
@transition Published Sent SendResultToParent ResultSentToParent
@rule PublishResult requires studentId approvedAt
@rule SendResultToParent requires guardianEmail guardianPhone
- studentId foreignId:students required cascadeOnDelete
- status enum:Draft,Published,Sent default:Draft
- studentName string:150 required
- admissionNumber string:60 required
- guardianEmail string:191 nullable
- guardianPhone string:30 nullable
- approvedAt datetime nullable
```

Behavior directives generate Laravel extension points:

- `@search` restricts repository search to allowed columns.
- `@commands` creates service and controller methods for named business actions.
- `@events` preserves event intent; CRUD methods also dispatch `ModelCreated`, `ModelUpdated`, and `ModelDeleted` events.
- `@state` documents the allowed state set.
- `@transition From To Command Event` creates command methods that validate and apply state transitions when a `status` or `state` field exists.
- `@rule Command requires fieldA fieldB` creates precondition checks before generated command methods run.
- `@route` customizes generated route prefixes when `--routes` is used.
- `@upload` opts an entity into end-to-end Excel/CSV upload generation. Without it, no import class, upload controller method, upload service method, or upload route is generated.

Upload accepts optional settings:

```text
score:
@upload field:file mimes:csv,xls,xlsx max:4096
- studentId foreignId:students required
- total decimal:5,2 required
```

Supported field types include:

```text
string, text, mediumText, longText, integer, int, number,
bigInteger, unsignedBigInteger, foreignId, boolean, bool,
date, datetime, timestamp, time, decimal, float, double,
json, uuid, ulid, enum
```

Supported field modifiers include:

```text
required, nullable, unique, index, default:value, unsigned,
constrained, constrained:table, references:table,
cascadeOnDelete, nullOnDelete, restrictOnDelete, cascadeOnUpdate,
hidden, nativeEnum
```

Supported entity modifiers include:

```text
table:custom_table_name, softDeletes
```

### Database-Agnostic Spec Mode

Spec-driven scaffolding is intentionally database-agnostic. It does not inspect the active database connection and generated migrations use Laravel's Schema Builder instead of driver-specific SQL.

For portability, `enum:a,b,c` generates a `string` column and an `in:a,b,c` validation rule by default. If you explicitly want a native database enum column, add the `nativeEnum` modifier:

```text
payment:
- status enum:pending,paid,failed default:pending
- providerStatus enum:pending,paid,failed nativeEnum default:pending
```

Database-first scaffolding may still use driver-specific introspection depending on your database driver. Use spec mode when you need portable migrations across MySQL, PostgreSQL, SQLite, and other Laravel-supported databases.

---

### Modular Monolith Output

Standard Laravel output remains the default. To generate module-scoped code from a spec, assign entities to modules:

```text
@app architecture:modular

module Academics:
student table:students:
- firstName string:100 required
- lastName string:100 required

module Billing:
payment table:payments:
@upload field:file mimes:csv,xls,xlsx max:4096
- reference string:80 required unique
- amount decimal:12,2 required
- status enum:pending,paid,failed default:pending
```

You can also assign a module on an entity:

```text
student module:Academics table:students:
- firstName string:100 required
```

Or with a directive:

```text
student table:students:
@module Academics
- firstName string:100 required
```

Modular generation writes files under `app/Modules/{Module}` and registers each module route file through the application's root `routes/api.php`, so fresh Laravel 11/12 apps can discover the routes.

Database-first scaffolding also supports modular output:

```bash
php artisan laravel:scaffold Student --table=students --module=Academics --routes
```

When the provided table does not match Laravel's conventional model table name, the generated model includes `protected $table = '...'`.

---

## What Gets Generated

From a single spec, Laravel Scaffold can generate:

- ✅ Eloquent models (fillable & hidden handled)
- ✅ Services & repositories
- ✅ Controllers
- ✅ Form Request validation classes
- ✅ Database migrations
- ✅ API routes (optional)

You can also generate **only migrations**:

```bash
php artisan laravel:scaffold --spec=api.spec --migration-only
```

---

## Why Spec-Driven?

Spec-driven development ensures:

- Database schema
- Validation rules
- API structure

…all come from **one source of truth**.

This dramatically reduces:
- bugs
- inconsistencies
- onboarding time

---

## Dry-Run Mode (Safe by Default)

Preview what will be generated **without writing files**:

```bash
php artisan laravel:scaffold --spec=api.spec --dry-run
```

---

## Example Output Structure

```
app/
 ├── Models/User.php
 ├── Services/UserService.php
 ├── Repositories/UserRepository.php
 ├── Http/
 │   ├── Controllers/UserController.php
 │   └── Requests/
 │       ├── StoreUserRequest.php
 │       └── UpdateUserRequest.php
database/
 └── migrations/
```

---

## When This Tool Shines

Laravel Scaffold is ideal if you:

- Build multiple Laravel backends
- Work on ERP, SaaS, or internal systems
- Want consistent architecture across projects
- Are tired of rewriting CRUD
- Want faster backend delivery without shortcuts

---

## Production Use

This tool has been used in **multiple real-world projects** to:
- speed up backend delivery
- standardize API structure
- reduce boilerplate and human error

---

## Philosophy

This is **not** a “magic CRUD generator”.

It enforces:
- separation of concerns
- explicit structure
- predictable output

You stay in control — the tool just removes the busy work.

---

## Roadmap

Planned improvements include:
- foreign key detection
- richer spec syntax (`string:150`, `default:true`)
- OpenAPI generation
- frontend SDK scaffolding

---

## Contributing

This project is actively maintained and opinionated by the author.

- Bug reports and feature discussions are welcome via Issues
- Pull requests should be discussed first before implementation
- Architectural changes will be evaluated carefully to maintain consistency

The goal is to keep the tool stable, predictable, and production-ready.

## Project Governance

Laravel Scaffold follows a Benevolent Dictator For Life (BDFL) model.
The maintainer retains final decision-making authority to ensure
long-term consistency and quality.

---

## License

MIT
