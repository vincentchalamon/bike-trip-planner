<?php

declare(strict_types=1);

namespace App\Llm\Poc;

/**
 * Representative French chat prompts used by the tool-calling POC to compare
 * the new symfony/ai-agent flow with the current JSON-envelope approach.
 *
 * Each case declares the expected action + params (or `null` for an "info"
 * response that should not trigger any tool call). The set deliberately mixes
 * unambiguous, paraphrased, and noisy phrasings so we can spot brittleness on
 * a small local model (llama3.2:3b).
 */
final class PocPromptSuite
{
    /**
     * @return list<array{prompt: string, expected_action: string|null, expected_params: array<string, mixed>}>
     */
    public static function cases(): array
    {
        return [
            // split_stage
            ['prompt' => "Coupe l'étape 2 en deux.", 'expected_action' => 'split_stage', 'expected_params' => ['stage' => 2]],
            ['prompt' => "Divise l'étape 3 s'il te plaît.", 'expected_action' => 'split_stage', 'expected_params' => ['stage' => 3]],
            ['prompt' => "Découpe l'étape 5.", 'expected_action' => 'split_stage', 'expected_params' => ['stage' => 5]],

            // merge_stages
            ['prompt' => 'Fusionne les étapes 1 et 2.', 'expected_action' => 'merge_stages', 'expected_params' => ['stages' => [1, 2]]],
            ['prompt' => 'Combine les étapes 4 et 5 en une seule.', 'expected_action' => 'merge_stages', 'expected_params' => ['stages' => [4, 5]]],
            ['prompt' => "Regroupe l'étape 2 avec la 3.", 'expected_action' => 'merge_stages', 'expected_params' => ['stages' => [2, 3]]],

            // add_waypoint
            ['prompt' => 'Ajoute un détour par Cluny.', 'expected_action' => 'add_waypoint', 'expected_params' => ['name' => 'Cluny']],
            ['prompt' => 'Insère Tournus comme étape 3.', 'expected_action' => 'add_waypoint', 'expected_params' => ['name' => 'Tournus', 'stage' => 3]],
            ['prompt' => "Passe par Beaune avant d'arriver.", 'expected_action' => 'add_waypoint', 'expected_params' => ['name' => 'Beaune']],

            // change_accommodation
            ['prompt' => "Change l'hébergement de l'étape 2 pour un camping.", 'expected_action' => 'change_accommodation', 'expected_params' => ['stage' => 2, 'type' => 'camp_site']],
            ['prompt' => "Pour l'étape 4, mets un hôtel.", 'expected_action' => 'change_accommodation', 'expected_params' => ['stage' => 4, 'type' => 'hotel']],
            ['prompt' => 'Étape 1 : je préfère dormir en gîte.', 'expected_action' => 'change_accommodation', 'expected_params' => ['stage' => 1, 'type' => 'guest_house']],

            // adjust_distance
            ['prompt' => "L'étape 1 fait trop court, mets 80 km.", 'expected_action' => 'adjust_distance', 'expected_params' => ['stage' => 1, 'km' => 80.0]],
            ['prompt' => "Allonge l'étape 3 à 100 km.", 'expected_action' => 'adjust_distance', 'expected_params' => ['stage' => 3, 'km' => 100.0]],
            ['prompt' => "Raccourcis l'étape 4 à 50 kilomètres.", 'expected_action' => 'adjust_distance', 'expected_params' => ['stage' => 4, 'km' => 50.0]],

            // change_route
            ['prompt' => "Refais l'itinéraire complet.", 'expected_action' => 'change_route', 'expected_params' => []],
            ['prompt' => 'Recalcule tout le tracé pour passer par la côte.', 'expected_action' => 'change_route', 'expected_params' => []],

            // info — must NOT trigger any tool
            ['prompt' => 'Bonjour !', 'expected_action' => null, 'expected_params' => []],
            ['prompt' => 'Quelle est la différence entre gravel et bikepacking ?', 'expected_action' => null, 'expected_params' => []],
            ['prompt' => 'Merci, super.', 'expected_action' => null, 'expected_params' => []],
        ];
    }
}
