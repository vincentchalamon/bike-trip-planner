<?php

declare(strict_types=1);

namespace App\JsonSchema;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Symfony\Validator\Metadata\Property\Restriction\PropertySchemaRestrictionMetadataInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Keeps only the exclusive bound of a `GreaterThan` / `LessThan` (hence `Positive`) schema.
 *
 * API Platform emits `minimum: 0, exclusiveMinimum: 0` for `#[Assert\Positive]`: the draft-4
 * shape, where `exclusiveMinimum` was a boolean modifier of `minimum`. OpenAPI 3.1 is JSON
 * Schema 2020-12, where `exclusiveMinimum` is a number of its own and the inclusive bound next
 * to it is redundant; Redocly rejects the pair (`no-mixed-number-range-constraints`). The
 * draft-4 export is unaffected: BackwardCompatibleSchemaFactory derives `minimum` from
 * `exclusiveMinimum` itself.
 */
final readonly class ExclusiveBoundRestriction implements PropertySchemaRestrictionMetadataInterface
{
    public function __construct(
        private PropertySchemaRestrictionMetadataInterface $decorated,
        private string $inclusiveKey,
        private string $exclusiveKey,
    ) {
    }

    public function create(Constraint $constraint, ApiProperty $propertyMetadata): array
    {
        $restriction = $this->decorated->create($constraint, $propertyMetadata);

        if (\array_key_exists($this->exclusiveKey, $restriction)
            && ($restriction[$this->inclusiveKey] ?? null) === $restriction[$this->exclusiveKey]) {
            unset($restriction[$this->inclusiveKey]);
        }

        return $restriction;
    }

    public function supports(Constraint $constraint, ApiProperty $propertyMetadata): bool
    {
        return $this->decorated->supports($constraint, $propertyMetadata);
    }
}
