<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\ShareLink;
use App\Entity\TripShare;
use App\State\TripShareCreateProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publishes a trip and answers with the address rather than the row.
 *
 * Wraps the HTTP processor untouched — the uniqueness guarantee, the pre-check and the unique
 * index behind it are the same ones, and re-implementing any part of that for a second
 * transport is how two callers end up with two different rules.
 *
 * @implements ProcessorInterface<TripShare, ShareLink>
 */
final readonly class McpShareTripProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TripShare, TripShare> $shares
     */
    public function __construct(
        #[Autowire(service: TripShareCreateProcessor::class)]
        private ProcessorInterface $shares,
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ShareLink
    {
        $share = $this->shares->process($data, $operation, $uriVariables, $context);

        return new ShareLink(
            url: rtrim($this->frontendUrl, '/').'/s/'.$share->getShortCode(),
            shortCode: $share->getShortCode(),
            createdAt: $share->getCreatedAt(),
        );
    }
}
