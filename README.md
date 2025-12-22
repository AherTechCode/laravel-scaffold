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

---

## License

MIT
