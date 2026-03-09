<?php

declare(strict_types=1);

namespace App\Scanner;

interface OverpassStatusCheckerInterface
{
    public function isReady(): bool;
}
