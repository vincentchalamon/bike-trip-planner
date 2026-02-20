---
name: typegen
description: Regenerate TypeScript types from backend OpenAPI spec and verify frontend compilation
---

When backend DTOs change, run the type generation pipeline:

1. Ensure the PHP backend is running: `docker compose ps php`
2. Generate types: `make typegen`
3. Check for TypeScript errors: `make tsc`
4. If errors exist, fix the frontend code to match the new types
5. Report what changed in `pwa/src/lib/api/schema.d.ts`
