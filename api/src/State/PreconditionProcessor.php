<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Concurrency\IfMatch;
use App\Concurrency\VersionPrecondition;
use App\Repository\TripRequestRepositoryInterface;

/**
 * Requires an `If-Match` precondition on every operation that edits a trip's structure.
 *
 * Runs at the write stage of API Platform's processor chain, which is **after** the provider
 * chain and therefore after {@see \ApiPlatform\Symfony\Security\State\AccessCheckerProvider}.
 * That ordering is the point: a `kernel.request` listener would answer 412 or 428 before
 * authorization ran, turning the pair into a version oracle on other people's trips. Past the
 * provider, a caller who has no business seeing the trip has already been answered with the
 * 404 that ADR-038 masks their 403 as.
 *
 * Two checks guard each write, and they are not redundant:
 *
 *  - here, the header's presence (428) and syntax (400), plus a fail-fast 412 against the
 *    current version, which spares an expensive processor body — the manual-accommodation
 *    one geocodes an address before it writes anything;
 *  - in the repository, the authoritative comparison, performed under the write lock. That
 *    is the one that actually prevents a lost update; this one only refuses early.
 *
 * Wired in `config/services.php` rather than by an attribute here: it decorates the HTTP write
 * chain and the MCP one, which api-platform/mcp builds separately.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final readonly class PreconditionProcessor implements ProcessorInterface
{
    /**
     * Operations carrying this extra property demand the header. The flag is declared on the
     * operation rather than inferred from a path list, and
     * {@see \App\Tests\Unit\State\PreconditionCoverageTest} checks it against the processors
     * that actually write, so neither side can drift alone.
     */
    public const string EXTRA_PROPERTY = 'requires_if_match';

    /**
     * @param ProcessorInterface<mixed, mixed> $decorated
     */
    public function __construct(
        private ProcessorInterface $decorated,
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (true === ($operation->getExtraProperties()[self::EXTRA_PROPERTY] ?? false)) {
            $this->assertPrecondition($uriVariables, $context);
        }

        return $this->decorated->process($data, $operation, $uriVariables, $context);
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    private function assertPrecondition(array $uriVariables, array $context): void
    {
        // Throws 428 when absent, 400 when malformed.
        $precondition = IfMatch::fromContext($context);

        $tripId = $uriVariables['tripId'] ?? $uriVariables['id'] ?? null;
        if (!\is_string($tripId)) {
            return;
        }

        VersionPrecondition::assert($precondition->expectedVersion, $this->tripStateManager->getVersion($tripId), $tripId);
    }
}
