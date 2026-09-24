<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\WriteAcknowledgement;
use App\Entity\TripShare;
use App\State\TripShareDeleteProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Takes the public link back, and says so in words instead of a status code.
 *
 * The HTTP processor answers `204 No Content`, which is the right answer on a transport whose
 * status line is part of the message. Here there is no status line: a `Response` object
 * normalised into the tool result would tell a model nothing, so the same work is done and the
 * outcome is stated.
 *
 * @implements ProcessorInterface<TripShare, WriteAcknowledgement>
 */
final readonly class McpUnshareTripProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TripShare, mixed> $shares
     */
    public function __construct(
        #[Autowire(service: TripShareDeleteProcessor::class)]
        private ProcessorInterface $shares,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WriteAcknowledgement
    {
        $this->shares->process($data, $operation, $uriVariables, $context);

        return new WriteAcknowledgement(
            result: 'The share link has been revoked. The address no longer resolves, for anyone who kept it.',
        );
    }
}
