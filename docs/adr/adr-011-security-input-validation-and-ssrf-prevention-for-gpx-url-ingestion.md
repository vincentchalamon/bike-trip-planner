# ADR-011: Security, Input Validation, and SSRF Prevention for GPX/URL Ingestion

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

The entry point for generating a trip in Bike Trip Planner requires the user to provide either a URL (from Komoot) or a raw
`.gpx` file upload. Because the API Platform backend (PHP 8.5) acts as the stateless processing engine, it must ingest
and parse these external inputs.

This introduces two critical OWASP Top 10 security vulnerabilities:

1. **Server-Side Request Forgery (SSRF):** If the backend blindly fetches any URL provided by the user, an attacker
   could force the PHP server to make HTTP requests to internal Docker networks or cloud provider metadata endpoints (e.g., AWS `169.254.169.254`).
2. **XML External Entities (XXE) and Denial of Service (DoS):** GPX files are standard XML documents. Malicious XML
   payloads (like the "Billion Laughs" attack) can exhaust server memory or read local environment files if the XML
   parser attempts to resolve external entities.

We must define a strict security perimeter that guarantees the backend can safely ingest routes without exposing the
infrastructure to SSRF or XML-based attacks.

### Architectural Requirements

| Requirement        | Description                                                                                                     |
|--------------------|-----------------------------------------------------------------------------------------------------------------|
| SSRF Prevention    | The backend HTTP client must strictly limit where it can send requests and must not follow malicious redirects. |
| XML Hardening      | The GPX parser must explicitly reject external entities and strictly bound memory usage.                        |
| Payload Limitation | The system must reject unrealistically large files before they reach the PHP application layer.                 |
| DX / UX            | Validation failures must return clear, RFC 7807 compliant error messages to the Next.js frontend.               |

---

## Decision Drivers

* **Infrastructure Protection** — A single successful SSRF attack can compromise the entire Docker host.
* **Service Availability** — XML bombs can cause Out-Of-Memory (OOM) errors, crashing the PHP-FPM container for all
  users.
* **Framework Synergy** — Leverage API Platform and Symfony's native validation and networking tools rather than writing
  custom security middleware.

---

## Considered Options

### Option A: Basic Regex Validation and Default Parsers

Rely solely on a regex to check if the URL contains `komoot.com`, and use the default `XMLReader` settings.

* *Cons:* Extremely vulnerable. Attackers can bypass regex checks using open redirects (e.g.,
  `http://komoot.com.attacker.com` or exploiting a redirect vulnerability on Komoot's actual site to bounce back to
  `localhost`).

### Option B: External Serverless Sandbox (AWS Lambda)

Offload all fetching and GPX parsing to an isolated, ephemeral serverless function.

* *Cons:* Introduces massive architectural complexity, cloud vendor lock-in, and violates the local-first, containerized
  deployment strategy of the MVP.

### Option C: Strict Allowlisting, Scoped HTTP Clients, and Hardened XMLReader (Chosen)

Utilize Symfony's scoped `HttpClient` to enforce domain-level restrictions and disable redirects. Combine this with
strict API Platform DTO validation and a hardened `XMLReader` configuration that disables network access during parsing.

---

## Decision Outcome

**Chosen: Option C (Strict Allowlisting, Scoped HTTP Clients, and Hardened XMLReader)**

### Why Other Options Were Rejected

Option A is dangerously naive for modern web applications. URL validation is notoriously difficult to get right with
regex alone due to DNS rebinding and open redirects. Option B is architectural overkill for a project of this scope.

Option C leverages the built-in capabilities of Symfony 8 and PHP 8.5 to create an impenetrable security boundary
directly at the application's edge.

---

## Implementation Strategy

### 10.1 — SSRF Prevention via Scoped HttpClient

Instead of using a generic HTTP client to fetch the user's URL, we will configure a scoped client in Symfony that is
mathematically restricted to the Komoot domain and refuses to follow infinite redirects.

**Configuration:** `api/config/packages/framework.yaml`

```yaml
framework:
  http_client:
    scoped_clients:
      komoot.client:
        # Force the base URI to ensure the request CANNOT go anywhere else
        base_uri: 'https://www.komoot.com'
        # Strictly limit redirects to prevent "Open Redirect" SSRF bypasses
        max_redirects: 2
        # Fail fast to prevent tying up PHP-FPM workers
        timeout: 10
        headers:
          Accept: 'application/gpx+xml, text/html'
```

### 10.2 — API Platform Input Validation

Before the URL is ever passed to the HTTP client, it must pass API Platform's strict validation layer using modern PHP
Attributes.

**File:** `api/src/ApiResource/TripRequest.php`

```php
namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(/* ... */)]
final class TripRequest
{
    #[Assert\NotBlank]
    #[Assert\Url(
        requireTld: true, 
        protocols: ['https'] // Strictly forbid http://, ftp://, file://
    )]
    #[Assert\Regex(
        pattern: '/^https:\/\/www\.komoot\.com\/(tour|collection)\/\d+/',
        message: 'The URL must be a valid Komoot tour or collection.'
    )]
    public string $komootUrl;
}
```

### 10.3 — Hardening the XML GPX Parser (XXE Protection)

Although PHP 8.0+ (and libxml2 >= 2.9.0) disables external entity loading by default, we will explicitly configure the
`XMLReader` (from ADR-004) to prevent any network calls or entity substitutions during parsing, acting as a
defense-in-depth measure.

**File:** `api/src/Spatial/GpxStreamParser.php`

```php
namespace App\Spatial;

use XMLReader;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class GpxStreamParser
{
    public function parse(string $filePath): iterable
    {
        $reader = new XMLReader();
        
        // Disable network access for the XML parser (prevents fetching external DTDs)
        // Disable entity substitution (prevents Billion Laughs DoS)
        $options = LIBXML_NONET | LIBXML_NOENT; 
        
        if (!$reader->open($filePath, null, $options)) {
            throw new BadRequestHttpException('Invalid or corrupted GPX file.');
        }

        // ... continue with stream parsing as defined in ADR-004
        
        $reader->close();
    }
}
```

### 10.4 — Payload Size Restrictions (Nginx & PHP-FPM)

To prevent users from uploading a 500MB GPX file that exhausts disk space or causes a timeout, strict limits are placed
at the infrastructure level (Nginx) and the PHP-FPM level.

**Configuration:** `docker/nginx/default.conf`

```nginx
server {
    # ...
    # Limit file uploads to 30MB (A massive 1500km GPX is rarely larger than 10MB)
    client_max_body_size 30M; 
}
```

**Configuration:** `docker/php/php.ini`

```ini
; Restrict execution time and memory for the parsing scripts
upload_max_filesize = 30M
post_max_size = 32M
memory_limit = 128M
max_execution_time = 30
```

---

## Verification

1. **SSRF Unit Test:** Create a test invoking the `TripGenerationProcessor` with a URL like
   `https://www.komoot.com@localhost:3000/metrics`. Assert that the API Platform validation layer throws an RFC 7807 400
   Bad Request error before the HTTP client is ever invoked.
2. **XXE Playwright Test:** Create a malicious `payload.gpx` containing a "Billion Laughs" XML bomb.

    ```xml
    <?xml version="1.0"?>
    <!DOCTYPE lolz [
            <!ENTITY lol "lol">
            <!ENTITY lol1 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
            ]>
    <gpx>
        <trk>
            <name>&lol9;</name>
        </trk>
    </gpx>
    ```

    Upload this file via the Next.js frontend in a Playwright E2E test. Assert that the server responds with a
    `400 Bad Request` and does not crash the Docker container (memory limit respected).

3. **Redirect Test:** Mock a server that responds to a Komoot URL with a `301 Redirect` to `http://169.254.169.254` (AWS
   Metadata IP). Assert that the `ScopedHttpClient` throws a `RedirectionException` and refuses to connect to the
   internal IP.

---

## Consequences

### Positive

* **Enterprise-Grade Security:** The backend is immune to the most common and dangerous vulnerabilities associated with
  XML parsing and URL fetching.
* **Fail-Fast Mechanics:** Malicious inputs are rejected at the edge (Nginx or Symfony Validator) in milliseconds,
  preserving CPU cycles for legitimate users.
* **Standards Compliance:** By leveraging API Platform's validation, frontend users receive standardized, localized
  error messages (JSON-LD / RFC 7807) automatically when providing a bad URL.

### Negative

* **Legitimate Edge Cases:** If a user attempts to upload an exceptionally highly-detailed, uncompressed GPX file larger
  than 30MB, they will be blocked. They will need to compress or decimate their file using a third-party tool before
  using Bike Trip Planner.

### Neutral

* The strict Komoot domain requirement means users cannot paste URLs from other services (e.g., Strava or RideWithGPS).
  If integration with other platforms is required in Lot 2, new scoped HTTP clients and regex rules must be explicitly
  audited and added to the configuration.
