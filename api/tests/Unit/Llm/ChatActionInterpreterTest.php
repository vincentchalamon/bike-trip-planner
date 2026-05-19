<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\ChatActionInterpreter;
use App\Llm\Dto\ChatAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatActionInterpreterTest extends TestCase
{
    private ChatActionInterpreter $interpreter;

    #[\Override]
    protected function setUp(): void
    {
        $this->interpreter = new ChatActionInterpreter();
    }

    #[Test]
    public function parsesSplitStageAction(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'split_stage',
                'params' => ['stage' => 3],
                'response' => "Très bien, je découpe l'étape 3 en deux.",
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_SPLIT_STAGE, $action->action);
        $this->assertSame(['stage' => 3], $action->params);
        $this->assertStringContainsString('étape 3', $action->response);
    }

    #[Test]
    public function parsesMergeStagesAction(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'merge_stages',
                'params' => ['stages' => [2, 3]],
                'response' => 'Je fusionne les étapes 2 et 3.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_MERGE_STAGES, $action->action);
        $this->assertSame(['stages' => [2, 3]], $action->params);
    }

    #[Test]
    public function acceptsMergeStagesInReversedOrder(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'merge_stages',
                'params' => ['stages' => [3, 2]],
                'response' => 'Je fusionne les étapes 2 et 3.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_MERGE_STAGES, $action->action);
        $this->assertSame(['stages' => [3, 2]], $action->params);
    }

    #[Test]
    public function rejectsNonConsecutiveMergeStages(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'merge_stages',
                'params' => ['stages' => [2, 5]],
                'response' => 'On fusionne 2 et 5.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
        $this->assertSame([], $action->params);
    }

    #[Test]
    public function parsesAddWaypointWithoutStage(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'add_waypoint',
                'params' => ['name' => 'Mont Cassel', 'stage' => null],
                'response' => 'Détour ajouté par le Mont Cassel.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_ADD_WAYPOINT, $action->action);
        $this->assertSame(['name' => 'Mont Cassel', 'stage' => null], $action->params);
    }

    #[Test]
    public function parsesAddWaypointWithStage(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'add_waypoint',
                'params' => ['name' => 'Mont Cassel', 'stage' => 2],
                'response' => 'Détour ajouté.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_ADD_WAYPOINT, $action->action);
        $this->assertSame(['name' => 'Mont Cassel', 'stage' => 2], $action->params);
    }

    #[Test]
    public function rejectsAddWaypointWithEmptyName(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'add_waypoint',
                'params' => ['name' => '   ', 'stage' => null],
                'response' => 'OK',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
    }

    #[Test]
    public function parsesChangeAccommodationAction(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'change_accommodation',
                'params' => ['stage' => 2, 'type' => 'guest_house'],
                'response' => "J'ajuste l'hébergement.",
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_CHANGE_ACCOMMODATION, $action->action);
        $this->assertSame(['stage' => 2, 'type' => 'guest_house'], $action->params);
    }

    #[Test]
    public function changeAccommodationFallsBackToInfoWhenTypeIsNotInTheAllowlist(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'change_accommodation',
                'params' => ['stage' => 2, 'type' => 'palace'],
                'response' => 'Bien noté.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
        $this->assertSame([], $action->params);
    }

    #[Test]
    public function parsesAdjustDistanceAction(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'adjust_distance',
                'params' => ['stage' => 5, 'km' => 95],
                'response' => 'OK.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_ADJUST_DISTANCE, $action->action);
        $this->assertSame(['stage' => 5, 'km' => 95.0], $action->params);
    }

    #[Test]
    public function parsesAdjustDistanceWithFloat(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'adjust_distance',
                'params' => ['stage' => 5, 'km' => 87.5],
                'response' => 'OK.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(['stage' => 5, 'km' => 87.5], $action->params);
    }

    #[Test]
    public function parsesInfoAction(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'info',
                'params' => new \stdClass(),
                'response' => 'Le gravel désigne un type de vélo.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
        $this->assertSame([], $action->params);
        $this->assertSame('Le gravel désigne un type de vélo.', $action->response);
    }

    #[Test]
    public function unknownActionDegradesToInfo(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'avoid_traffic',
                'params' => ['stage' => 4],
                'response' => 'Je recalcule en évitant le trafic.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
        $this->assertSame([], $action->params);
        $this->assertSame('Je recalcule en évitant le trafic.', $action->response);
    }

    #[Test]
    public function malformedJsonFallsBackToInfo(): void
    {
        $action = $this->interpreter->interpret('not a json payload');

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
        $this->assertSame([], $action->params);
        $this->assertSame(ChatActionInterpreter::DEFAULT_FALLBACK_MESSAGE, $action->response);
    }

    #[Test]
    public function emptyResponseFallsBackToDefaultMessage(): void
    {
        $action = $this->interpreter->interpret('');

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
        $this->assertSame(ChatActionInterpreter::DEFAULT_FALLBACK_MESSAGE, $action->response);
    }

    #[Test]
    public function jsonWrappedInCodeFenceIsTolerated(): void
    {
        $payload = "```json\n".json_encode([
            'action' => 'split_stage',
            'params' => ['stage' => 1],
            'response' => 'OK.',
        ], \JSON_THROW_ON_ERROR)."\n```";

        $action = $this->interpreter->interpret($payload);

        $this->assertSame(ChatAction::ACTION_SPLIT_STAGE, $action->action);
        $this->assertSame(['stage' => 1], $action->params);
    }

    #[Test]
    public function jsonEmbeddedInProseIsExtracted(): void
    {
        $payload = 'Voici la réponse : '.json_encode([
            'action' => 'info',
            'params' => [],
            'response' => 'Bonjour.',
        ], \JSON_THROW_ON_ERROR).' Fin.';

        $action = $this->interpreter->interpret($payload);

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
        $this->assertSame('Bonjour.', $action->response);
    }

    #[Test]
    public function missingStageParamFallsBackToInfo(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'split_stage',
                'params' => [],
                'response' => 'OK.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
    }

    #[Test]
    public function negativeStageFallsBackToInfo(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'split_stage',
                'params' => ['stage' => -1],
                'response' => 'OK.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_INFO, $action->action);
    }

    #[Test]
    public function stringStageIsCoerced(): void
    {
        $action = $this->interpreter->interpret(
            json_encode([
                'action' => 'split_stage',
                'params' => ['stage' => '3'],
                'response' => 'OK.',
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(ChatAction::ACTION_SPLIT_STAGE, $action->action);
        $this->assertSame(['stage' => 3], $action->params);
    }
}
