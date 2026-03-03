# ADR-008: High-Fidelity PDF Roadbook Generation Strategy

**Status:** Revoked - **This ADR has been revoked, PDF generation has been removed from the features.**

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

A core requirement of Lot 1 is the ability for the user to export their generated bikepacking trip into a professional,
high-fidelity PDF "Roadbook" that can be viewed offline on a mobile device or printed.

Because Bike Trip Planner utilizes a "local-first" architecture (ADR-003), the entire state of the trip (stages, alerts,
geospatial data) lives in the user's browser (Zustand store). We need a mechanism to convert this complex JSON state
into a beautifully formatted PDF that supports modern CSS layout properties (Flexbox, CSS Grid) to match the
application's Tailwind CSS design system.

### Architectural Requirements

| Requirement               | Description                                                                                                                   |
|---------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| Visual Fidelity           | The PDF must support modern CSS3 standards (Flexbox, Grid, custom web fonts) and render crisp vector graphics/maps.           |
| Performance               | PDF generation must not block the browser's main thread or crash the user's device.                                           |
| Local-First Compatibility | The engine must accept the raw JSON state payload from the frontend and convert it into a document.                           |
| Containerization          | The solution must integrate cleanly into our existing Docker Compose environment without polluting the PHP runtime container. |

---

## Decision Drivers

* **Rendering Engine Capabilities** — Native PHP PDF libraries struggle significantly with modern CSS, whereas
  Chromium-based engines provide pixel-perfect HTML-to-PDF rendering.
* **Separation of Concerns** — Offloading PDF rendering to a dedicated microservice prevents the PHP backend from
  consuming massive amounts of RAM and CPU during concurrent generation requests.
* **Developer Experience (DX)** — The ability to write the PDF layout using a familiar templating engine (Twig) rather
  than complex procedural PDF drawing commands.

---

## Considered Options

### Option A: Client-Side Generation (`jsPDF` + `html2canvas`)

The Next.js frontend takes a "screenshot" of a hidden DOM element using Canvas and embeds it into a PDF wrapper.

* *Pros:* Fully offline, zero server infrastructure required.
* *Cons:* Extremely resource-intensive for the client's browser. Text is rendered as rasterized images (blurry,
  non-searchable, heavy file size), breaking accessibility and copy-paste functionality.

### Option B: Native PHP Libraries (`Dompdf` or `TCPDF`)

The Next.js frontend POSTs the JSON to the PHP backend, which renders it using a native PHP PDF library.

* *Pros:* Pure PHP implementation, no additional Docker containers needed.
* *Cons:* Complete lack of support for modern CSS standards (CSS Grid/Flexbox). Forces the developer to write archaic,
  table-based HTML layouts specifically for the PDF.

### Option C: Gotenberg Microservice with `sensiolabs/gotenberg-bundle` (Chosen)

The Next.js frontend POSTs the JSON state to the API Platform backend. The backend renders a Twig template using the
data, and sends the HTML to **Gotenberg**, a dedicated Docker-based API that wraps headless Chromium to generate the
PDF.

---

## Decision Outcome

**Chosen: Option C (Gotenberg Microservice)**

### Why Other Options Were Rejected

**Option A (Client-Side) rejected:**
Bikepackers often read roadbooks on their phones under bright sunlight. Text rendered as a blurry JPEG canvas is
unacceptable for readability. The PDF must contain selectable, vector-based text.

**Option B (Native PHP) rejected:**
Maintaining a separate, outdated CSS methodology (floats and tables) solely for Dompdf creates immense technical debt
and severely limits the design quality of the exported Roadbook.

### Why Option C was Chosen

* **Chromium Engine:** Gotenberg wraps headless Chromium, guaranteeing 100% compatibility with modern CSS, external
  stylesheets, and embedded fonts.
* **Stateless & Containerized:** Gotenberg is deployed as a standalone Docker container (`gotenberg/gotenberg:8`)
  exposing a simple API. It scales independently and doesn't bloat the Caddy container.
* **First-Class Symfony Integration:** The recently released `sensiolabs/gotenberg-bundle` provides native Twig
  integration, seamless asset management, and utilizes the blazing-fast Symfony HttpClient out of the box.

---

## Implementation Strategy

### 7.1 — Infrastructure (Docker Compose)

We add the official Gotenberg image to our `compose.yaml`.

**File:** `compose.yaml`

```yaml
services:
  # ... existing php and frontend services ...

  gotenberg:
    image: 'gotenberg/gotenberg:8' # Uses the latest major version 8
    ports:
      - '3000:3000'
    restart: unless-stopped
```

### 7.2 — Backend Bundle Installation

We install the official SensioLabs Gotenberg bundle in the PHP application.

```bash
docker compose exec php composer require sensiolabs/gotenberg-bundle
```

The bundle automatically configures a `GOTENBERG_DSN` pointing to the new container in the `.env` file (
`http://gotenberg:3000`).

### 7.3 — Backend Implementation (Twig to Gotenberg)

Because the API Platform architecture (ADR-001) is strictly JSON-based, we will create a dedicated custom endpoint
outside of the standard REST resources to handle the PDF stream.

**File:** `api/src/Controller/PdfExportController.php`

```php
namespace App\Controller;

use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\ApiResource\TripResponse;

final class PdfExportController extends AbstractController
{
    #[Route('/export-pdf', name: 'api_export_pdf', methods: ['POST'])]
    public function __invoke(
        Request $request,
        GotenbergPdfInterface $gotenbergPdf,
        ValidatorInterface $validator
    ): Response {
        // 1. Deserialize the incoming JSON payload from the frontend into the DTO
        $tripData = $this->serializer->deserialize($request->getContent(), TripResponse::class, 'json');
        
        // 2. Validate the incoming data to ensure it hasn't been tampered with
        $errors = $validator->validate($tripData);
        if (count($errors) > 0) {
            return $this->json($errors, Response::HTTP_BAD_REQUEST);
        }

        // 3. Generate the PDF via Gotenberg by passing the Twig template and the data
        return $gotenbergPdf
            ->html()
            ->content('pdf/roadbook.html.twig', [
                'trip' => $tripData,
            ])
            ->generate()
            ->stream(); // Returns the raw PDF binary stream directly to the client
    }
}
```

### 7.4 — Template Design (Twig)

We leverage Twig to construct the document. Gotenberg's Chromium engine allows us to inject a pre-compiled Tailwind CSS
file or use the CDN for styling the printable document.

**File:** `api/templates/pdf/roadbook.html.twig`

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>{{ trip.title }} - Roadbook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Force page breaks for each new stage */
        .page-break { page-break-before: always; }
    </style>
</head>
<body class="bg-white text-gray-900 p-8">
    <h1 class="text-4xl font-bold mb-4">{{ trip.title }}</h1>
    <p class="text-lg">Total Distance: {{ trip.totalDistance }} km</p>
    
    {% for stage in trip.stages %}
        <div class="page-break mt-10">
            <h2 class="text-2xl font-semibold">Stage {{ stage.dayNumber }}</h2>
            <p>Distance: {{ stage.distance }} km | Elevation: {{ stage.elevation }} m D+</p>
            
            <h3 class="text-xl mt-4 text-red-600">Warnings</h3>
            <ul class="list-disc pl-5">
                {% for warning in stage.warnings %}
                    <li>{{ warning.message }}</li>
                {% endfor %}
            </ul>
        </div>
    {% endfor %}
</body>
</html>
```

### 7.5 — Frontend Implementation

The frontend utilizes the `openapi-fetch` client (from ADR-002) to POST the current Zustand state to the endpoint,
receives the Blob, and triggers a browser download.

**File:** `pwa/src/lib/pdfExport.ts`

```typescript
import {useTripStore} from '@/store/useTripStore';

export const downloadPdfRoadbook = async () => {
    const tripState = useTripStore.getState().trip;
    if (!tripState) return;

    const response = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/export-pdf`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(tripState),
    });

    if (!response.ok) throw new Error('PDF Generation failed');

    // Convert the stream into a Blob and trigger a download
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `Bike Trip Planner_Roadbook_${tripState.id}.pdf`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
};
```

---

## Verification

1. **Docker Boot Validation:** Verify `gotenberg:8` boots correctly via `docker compose logs gotenberg` and exposes port 3000.
2. **End-to-End Test (Playwright):** - Load a complex trip into the application.

   * Click the "Export PDF" button.
   * Assert that a file download is triggered, that its MIME type is `application/pdf`, and that the file size is bytes.

3. **Visual Regression:** Open the generated PDF and manually verify that Tailwind classes (e.g., Flexbox column
   layouts, text colors) are respected and that pagination occurs correctly using CSS `page-break-before` rules.

---

## Consequences

### Positive

* **Unmatched Quality:** Generates pixel-perfect PDFs with full support for modern web standards, allowing the Roadbook
  to look exactly like a professionally designed brochure.
* **Separation of Concerns:** The CPU-heavy task of Chromium rendering is strictly confined to the Gotenberg container,
  keeping the PHP-FPM container highly available for API requests.
* **Simplified Backend Logic:** Using `sensiolabs/gotenberg-bundle` abstracts away the complex multipart/form-data HTTP
  requests, reducing PDF generation to just a few lines of code.

### Negative

* **Resource Consumption:** The Gotenberg Chromium container is relatively heavy (requires a few hundred MBs of RAM).
  Running this locally on older developer machines might cause slight resource strain.
* **Duplication of Styling:** Since the backend Twig template is responsible for rendering the PDF, some Tailwind UI
  components might need to be recreated in HTML/Twig, leading to a slight divergence from the Next.js React components.

### Neutral

* The PDF endpoint requires manually bypassing API Platform's standard JSON-LD serialization flow to return a raw binary
  stream, which requires a custom controller action.

---

## Sources

* [Gotenberg Official Repository](https://github.com/gotenberg/gotenberg)
* [Generating PDFs from HTML using Gotenberg - Medium](https://medium.com/@annabi.medamine/generating-pdfs-from-html-using-gotenberg-a-practical-integration-story-d1792080c00b)
* [sensiolabs/GotenbergBundle Official Repository](https://github.com/sensiolabs/GotenbergBundle)
* [How to generate a PDF file in a few lines of code with Symfony (SensioLabs Blog)](https://medium.com/the-sensiolabs-tech-blog/how-to-generate-a-pdf-file-in-a-few-lines-of-code-with-symfony-39786a679d29)
* [SymfonyLive Paris 2025: Du lego de composants pour un bundle Gotenberg !](https://live.symfony.com/2025-paris/schedule/du-lego-de-composants-pour-un-bundle-gotenberg)
*
