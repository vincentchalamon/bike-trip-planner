<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Format\WebsiteUrl;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input DTO for adding a manually-entered ("hors-app") accommodation to a stage.
 *
 * The address is geocoded backend-side (Nominatim) into the coordinates the
 * produced accommodation carries; a total price, when given, maps onto the
 * standard exact-price contract (min = max = price, isExactPrice = true).
 */
final class StageManualAccommodationRequest
{
    #[ApiProperty(description: 'Accommodation title (required).')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[ApiProperty(description: 'Postal address (required); geocoded backend-side into coordinates.')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    public string $address = '';

    #[ApiProperty(description: 'Optional total price in euros. Maps to estimatedPriceMin = estimatedPriceMax with isExactPrice = true.')]
    #[Assert\PositiveOrZero]
    public ?float $priceTotal = null;

    #[ApiProperty(description: 'Optional booking/listing link: an http(s) URL; a missing scheme is read as https.')]
    #[Assert\Length(max: 2048)]
    public ?string $url = null;

    /**
     * A scheme-less "www.gite.fr" is accepted (normalised to https), but any other
     * scheme, userinfo or non-host value is refused: the link is later served to
     * agents and share pages that do not filter it.
     */
    #[Assert\Callback]
    public function validateUrl(ExecutionContextInterface $context): void
    {
        if (null === $this->url || '' === trim($this->url) || null !== WebsiteUrl::normalize($this->url)) {
            return;
        }

        $context->buildViolation('This value is not a valid http(s) URL.')
            ->atPath('url')
            ->addViolation();
    }
}
