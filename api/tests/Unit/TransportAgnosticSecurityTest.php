<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins ADR-063: an authorization expression must not reference the HTTP request.
 *
 * `request` is an artefact of one transport. An API Platform operation can be
 * invoked without an HTTP request at all (MCP `tools/list`, the CLI), and the two
 * failure modes are both silent:
 *
 *   - with no request in scope the expression dies on
 *     `Unable to get property "attributes" of non-object "request"`;
 *   - with the *wrong* request in scope — on the MCP call path the current request
 *     is `POST /mcp`, not the trip route — `request.attributes.get('tripId')`
 *     returns null, {@see \App\Security\Voter\TripVoter::supports()} rejects null,
 *     the voter abstains and **every caller is denied, owner included**. ADR-038
 *     then masks that as a perfectly plausible 404.
 *
 * The replacement is the URI variable itself: `AccessCheckerProvider` binds every
 * one of them under its own name, in both the HTTP and the MCP provider chains.
 *
 * There is no ignore list. A legitimate need for `request` would mean reopening
 * ADR-063, not adding an exception here — that is the point.
 */
final class TransportAgnosticSecurityTest extends TestCase
{
    /** Directories holding every `#[ApiResource]` of the project. */
    private const array SCANNED_DIRS = ['src/ApiResource', 'src/Entity'];

    /**
     * Captures the expression of `security:` / `securityPostDenormalize:` arguments.
     *
     * Only the expression is captured, never the whole line: scanning raw file
     * contents would flag `TripRequest::class`, `use ...\RequestStack;` and local
     * `$request` variables, none of which are authorization expressions.
     */
    private const string SECURITY_PATTERN = '/\bsecurity(?:PostDenormalize|PostValidation)?:\s*(["\'])(.*?)\1/s';

    #[Test]
    public function noAuthorizationExpressionReadsTheHttpRequest(): void
    {
        $offenders = [];

        foreach ($this->securityExpressions() as $file => $expressions) {
            foreach ($expressions as $expression) {
                if (preg_match('/\brequest\b/', $expression)) {
                    $offenders[] = \sprintf('%s: %s', $file, $expression);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            \sprintf(
                "Authorization expression(s) referencing the HTTP request:\n  %s\n".
                'Use the URI variable directly instead, e.g. '."is_granted('TRIP_EDIT', tripId) ".
                'rather than '."is_granted('TRIP_EDIT', request.attributes.get('tripId')).".
                ' ADR-063: authorization belongs to the domain, not to a transport.',
                implode("\n  ", $offenders),
            ),
        );
    }

    #[Test]
    public function theScanActuallyFindsExpressions(): void
    {
        // Guards the guard: a regex that silently matches nothing would make the
        // assertion above vacuously green forever.
        $count = array_sum(array_map(count(...), $this->securityExpressions()));

        self::assertGreaterThan(
            30,
            $count,
            'The security-expression scan found almost nothing — the attribute syntax changed and this test '.
            'is no longer checking anything. Fix the pattern, do not lower the threshold.',
        );
    }

    /**
     * Relative file path => the security expressions it declares.
     *
     * @return array<string, list<string>>
     */
    private function securityExpressions(): array
    {
        $root = \dirname(__DIR__, 2);
        $found = [];

        foreach (self::SCANNED_DIRS as $dir) {
            $path = $root.'/'.$dir;
            self::assertDirectoryExists($path, \sprintf('%s not found.', $dir));

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }

                preg_match_all(self::SECURITY_PATTERN, (string) file_get_contents($file->getPathname()), $matches, \PREG_SET_ORDER);

                if ([] === $matches) {
                    continue;
                }

                $found[substr($file->getPathname(), \strlen($root) + 1)] = array_map(
                    static fn (array $m): string => $m[2],
                    $matches,
                );
            }
        }

        return $found;
    }
}
