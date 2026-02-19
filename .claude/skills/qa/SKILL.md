---
name: qa
description: Run the full QA pipeline (PHPStan, PHP-CS-Fixer, ESLint, Prettier, TypeScript checks) and report results
---

Run the project's quality assurance pipeline:

1. Execute `make qa` from the project root
2. Parse the output to identify:
    - PHPStan errors (with file paths and line numbers)
    - PHP-CS-Fixer violations
    - ESLint warnings/errors
    - Prettier formatting issues
    - TypeScript compilation errors
3. For each issue found, propose a fix
4. After fixing, re-run `make qa` to verify
