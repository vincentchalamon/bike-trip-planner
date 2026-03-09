---
name: qa
description: Run the full QA pipeline (PHP-CS-Fixer, Rector, PHPStan, ESLint, Prettier, TypeScript checks) and report results
---

Run the project's quality assurance pipeline:

1. Execute `make qa` from the project root
2. Parse the output to identify:
    - PHP-CS-Fixer violations (coding style)
    - Rector violations (automated refactoring rules)
    - PHPStan errors (with file paths and line numbers)
    - ESLint warnings/errors
    - Prettier formatting issues
    - TypeScript compilation errors
3. For each issue found, propose a fix
4. After fixing, re-run `make qa` to verify
