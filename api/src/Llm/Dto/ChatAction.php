<?php

declare(strict_types=1);

namespace App\Llm\Dto;

/**
 * Parsed LLaMA 3B chat brief.
 *
 * Produced by {@see \App\Llm\ChatActionInterpreter} from the JSON envelope emitted
 * by the dialogue system prompt. The processor uses {@see self::$action} to decide
 * whether to dispatch Messenger messages and forwards {@see self::$response} to
 * the frontend as the conversational reply.
 */
final readonly class ChatAction
{
    public const string ACTION_SPLIT_STAGE = 'split_stage';

    public const string ACTION_MERGE_STAGES = 'merge_stages';

    public const string ACTION_ADD_WAYPOINT = 'add_waypoint';

    public const string ACTION_CHANGE_ACCOMMODATION = 'change_accommodation';

    public const string ACTION_ADJUST_DISTANCE = 'adjust_distance';

    public const string ACTION_CHANGE_ROUTE = 'change_route';

    public const string ACTION_INFO = 'info';

    /**
     * `find_poi` is exclusively driven by the in-ride pipeline
     * ({@see \App\State\TripChatProcessor::processInRide()}), which constructs
     * a `ChatAction` directly and bypasses {@see \App\Llm\ChatActionInterpreter}.
     * It is intentionally NOT included in {@see self::SUPPORTED_ACTIONS} so the
     * planning interpreter rejects any hallucinated `find_poi` emission and
     * collapses it to `info`.
     */
    public const string ACTION_FIND_POI = 'find_poi';

    /**
     * @var list<string>
     */
    public const array SUPPORTED_ACTIONS = [
        self::ACTION_SPLIT_STAGE,
        self::ACTION_MERGE_STAGES,
        self::ACTION_ADD_WAYPOINT,
        self::ACTION_CHANGE_ACCOMMODATION,
        self::ACTION_ADJUST_DISTANCE,
        self::ACTION_CHANGE_ROUTE,
        self::ACTION_INFO,
    ];

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $action,
        public array $params,
        public string $response,
    ) {
    }
}
