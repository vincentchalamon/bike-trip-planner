# Security Policy

## Supported Versions

Only the latest version on the `main` branch is supported with security updates.

## Reporting a Vulnerability

**Please do not open a public issue for security vulnerabilities.**

Use GitHub's built-in **Private Vulnerability Reporting** to submit your report:

1. Go to the [Security Advisories page](https://github.com/vincentchalamon/bike-trip-planner/security/advisories/new)
2. Click **"Report a vulnerability"**
3. Provide a clear description, steps to reproduce, and potential impact

You will receive an acknowledgment within 48 hours. A fix will be prioritized based on severity.

## Scope

The following areas are in scope for security reports:

- **XML parsing** (GPX, KML) — XXE, billion laughs, entity expansion
- **URL handling** — SSRF via route fetcher (Komoot, Google MyMaps)
- **File uploads** — path traversal, oversized payloads
- **API endpoints** — injection, authentication bypass
- **Dependencies** — known CVEs in PHP or Node.js packages

## Out of Scope

- Denial of service via rate limiting (expected behavior)
- Issues in third-party services (OpenStreetMap, Komoot, weather APIs)
- Self-hosted instances with custom configurations
