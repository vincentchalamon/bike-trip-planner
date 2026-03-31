<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TripShare;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates a 256-bit token before delegating persistence to API Platform's PersistProcessor.
 *
 * @implements ProcessorInterface<TripShare, TripShare>
 */
final readonly class TripShareCreateProcessor implements ProcessorInterface
{
    public function __construct(
        /** @var ProcessorInterface<TripShare, TripShare> */
        #[Autowire(service: PersistProcessor::class)]
        private ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripShare
    {
        \assert($data instanceof TripShare);

        $data->generateToken();

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        \assert($result instanceof TripShare);

        return $result;
    }
}
