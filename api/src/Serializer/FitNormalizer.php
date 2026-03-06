<?php

declare(strict_types=1);

namespace App\Serializer;

final readonly class FitNormalizer extends AbstractStageNormalizer
{
    protected function format(): string
    {
        return 'fit';
    }

    protected function nameKey(): string
    {
        return 'courseName';
    }
}
