<?php

declare(strict_types=1);

namespace App\RouteParser;

/**
 * GPX-specific route parser that extends the base interface with title extraction.
 *
 * Follows ISP: only GPX parsers implement this.
 */
interface GpxRouteParserInterface extends RouteParserInterface
{
    /**
     * Extracts the first track name from a GPX string.
     */
    public function extractTitle(string $content): ?string;
}
