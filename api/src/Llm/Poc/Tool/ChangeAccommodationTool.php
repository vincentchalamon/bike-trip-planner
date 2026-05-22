<?php

declare(strict_types=1);

namespace App\Llm\Poc\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('change_accommodation', 'Change the accommodation type for a given stage. Use when the rider asks to switch to a hotel, camping, hostel, guesthouse, etc.')]
final class ChangeAccommodationTool
{
    /**
     * @param int    $stage The 1-based stage index
     * @param string $type  Accommodation type, one of: "camp_site", "hostel", "alpine_hut", "chalet", "guest_house", "motel", "hotel", "wilderness_hut", "shelter"
     */
    public function __invoke(int $stage, string $type): string
    {
        return \sprintf('Accommodation for stage %d set to %s.', $stage, $type);
    }
}
