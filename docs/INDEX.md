# ATTE Production Manager — Documentation Index

---

## 1. What Is This Project?

ATTE Production Manager is a **manufacturing inventory and production tracking system** built with vanilla PHP, jQuery, and Bootstrap. It manages component stock and production workflows for two assembly lines — SMD (Surface-Mount Device) pick-and-place assembly and THT (Through-Hole Technology) manual insertion — covering the full lifecycle from component inventory through BOM-based production recording to finished device tracking. It also supports Google Sheets export, FlowPin external data import, role-based user management, multi-location warehouse (magazine) tracking, and automated stock-level triggers.

---

## 2. Recommended Reading Order

Read these documents in order to ramp up efficiently:

1. [README.md](../README.md) (project root) — Overview, features, setup instructions
2. [CODEBASE_MAP.md](./system/CODEBASE_MAP.md) — Structural overview; find any file in under a minute
3. [ARCHITECTURE.md](./system/ARCHITECTURE.md) — High-level system design; how the pieces fit together
4. [ROUTING.md](./system/ROUTING.md) — How HTTP requests are routed; adding new pages
5. [DATABASE.md](./data/DATABASE.md) — MySQL schema reference; all tables and relationships
6. [MODULES.md](./code/MODULES.md) — Per-module guide; what's in each of the 19 module directories
7. [CLASSES.md](./code/CLASSES.md) — Domain class reference; core business logic classes
8. [INTEGRATIONS.md](./operations/INTEGRATIONS.md) — Google Sheets sync and FlowPin integration details
9. [CRON.md](./operations/CRON.md) — Scheduled jobs; what runs automatically and when
10. [STACK.md](./reference/STACK.md) — Technology stack; PHP 8.0+, MySQL 5.7+, dependencies

---

## 3. Documentation Reference Table

| Doc | What It Covers | When to Read |
|-----|----------------|--------------|
| [README.md](../README.md) | Project overview, features, setup | First — before anything else |
| [CODEBASE_MAP.md](./system/CODEBASE_MAP.md) | Directory structure, key file locations | Second — orient yourself in the codebase |
| [ARCHITECTURE.md](./system/ARCHITECTURE.md) | System design, domain model, request lifecycle, where to add features | Third — understand how it all connects |
| [ROUTING.md](./system/ROUTING.md) | HTTP routing (index.php), how URLs map to modules/actions, adding routes | Fourth — before working on pages or API endpoints |
| [DATABASE.md](./data/DATABASE.md) | MySQL schema — tables, columns, indexes, foreign keys, triggers | Fifth — before touching any data layer |
| [MODULES.md](./code/MODULES.md) | Each module's purpose, responsibilities, and key files | Sixth — when working on a specific feature area |
| [CLASSES.md](./code/CLASSES.md) | Domain classes (Api, DB, Utils, etc.) — purpose and usage | Seventh — when writing business logic |
| [INTEGRATIONS.md](./operations/INTEGRATIONS.md) | Google Sheets export/import, FlowPin data ingestion | Eighth — when working on external integrations |
| [CRON.md](./operations/CRON.md) | All scheduled jobs (6 scripts), intervals, what they do | Ninth — before modifying or adding cron jobs |
| [STACK.md](./reference/STACK.md) | PHP 8.0+, MySQL 5.7+, required extensions, tooling | Tenth — environment setup and dependency questions |

---

## 4. Folder Structure

```
docs/
├── INDEX.md                  ← you are here
├── system/
│   ├── ARCHITECTURE.md
│   ├── CODEBASE_MAP.md
│   └── ROUTING.md
├── data/
│   └── DATABASE.md
├── code/
│   ├── MODULES.md
│   └── CLASSES.md
├── operations/
│   ├── CRON.md
│   └── INTEGRATIONS.md
├── reference/
│   └── STACK.md
└── security/
    └── *(reserved for future security/audit docs)*
```

---

## 5. Practical FAQ for New Devs

**Q: How do I add a new page/route?**
→ See [ROUTING.md](./system/ROUTING.md) §"Adding Routes" and [ARCHITECTURE.md](./system/ARCHITECTURE.md) §"Where to Add New Features".

**Q: Where is the database schema?**
→ See [DATABASE.md](./data/DATABASE.md) — full MySQL schema with tables, columns, indexes, and triggers.

**Q: How does Google Sheets sync work?**
→ See [INTEGRATIONS.md](./operations/INTEGRATIONS.md) §"Google Sheets Integration" — covers export, import, and FlowPin ingestion.

**Q: What runs on the cron?**
→ See [CRON.md](./operations/CRON.md) — lists all 6 scheduled job scripts, their intervals, and what each one does.

**Q: What's the class layout?**
→ See [CLASSES.md](./code/CLASSES.md) — domain class map with purpose and key methods for each class under `src/classes/`.

**Q: Where do I find module X?**
→ See [MODULES.md](./code/MODULES.md) — directory listing of all 19 module folders under `public_html/components/`.

---

## 6. Keeping These Docs in Sync

These docs are maintained manually alongside the code. When you make a structural or architectural change:

- **Routing change** → update [ROUTING.md](./system/ROUTING.md)
- **New module or rename** → update [MODULES.md](./code/MODULES.md) and [CODEBASE_MAP.md](./system/CODEBASE_MAP.md)
- **Schema/DDL change** → update [DATABASE.md](./data/DATABASE.md)
- **New cron job** → add to [CRON.md](./operations/CRON.md)
- **New integration** → add section to [INTEGRATIONS.md](./operations/INTEGRATIONS.md)
- **New class or significant refactor** → update [CLASSES.md](./code/CLASSES.md) and [ARCHITECTURE.md](./system/ARCHITECTURE.md)

For team-level coordination, announce doc updates in the project channel so everyone knows to re-read the relevant section.