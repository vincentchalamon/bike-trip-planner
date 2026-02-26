<?php

declare(strict_types=1);

namespace App\Engine;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.engine')]
interface EngineInterface
{
}
