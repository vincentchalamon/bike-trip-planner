<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\Serializer\Encoder\EncoderInterface;

final class GpxEncoder implements EncoderInterface
{
    public function encode(mixed $data, string $format, array $context = []): string
    {
        if (!\is_string($data)) {
            throw new \InvalidArgumentException('GpxEncoder expects a string (GPX XML content).');
        }

        return $data;
    }

    public function supportsEncoding(string $format): bool
    {
        return 'gpx' === $format;
    }
}
