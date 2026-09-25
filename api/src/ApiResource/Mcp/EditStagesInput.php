<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\StageRequest;

/**
 * The arguments of `edit_stages`, and nothing else.
 *
 * Never instantiated: it publishes the `inputSchema` ({@see ShareTripInput}). The record the
 * arguments describe is {@see StageRequest}, named by `mcp_input`; `action` and `stageId` steer
 * the call instead of describing a stage, and are declared as such through `mcp_control`.
 *
 * ⚠ **The schema cannot say which field goes with which action, and that is a known limit.**
 * `SchemaFactory` builds one flat object out of a class, so every field below is published as
 * optional and there is no way to express the five-way union this really is. A real `oneOf`
 * would cost five separate tools, which is worse: the five are one concept — restructuring the
 * days of a trip — sharing one critical section, one version bump and one set of guards.
 *
 * Two things stand in for it, and both are deliberate: the description of `action` lists what
 * each branch needs, and a branch called without its field is refused by an error that names
 * the field. A model that guesses wrong is told what to send, once.
 */
final readonly class EditStagesInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip whose days are being restructured.')]
        public string $tripId,
        #[ApiProperty(description: <<<'TEXT'
            What to do. One of:
            - `add`: insert a new day. Needs `startPoint` and `endPoint`; `position` says where (0-based, default: at the end).
            - `update`: change an existing day. Needs `stageId`, plus any of `startPoint`, `endPoint`, `label`, `distance`.
            - `move`: reorder. Needs `stageId` and `toIndex` (0-based).
            - `delete`: remove a day and merge it with the one beside it. Needs `stageId`.
            - `rest_day`: insert a rest day AFTER a day. Needs `stageId`. The following day starts where it did; every date shifts by one.
            TEXT)]
        public string $action,
        #[ApiProperty(description: 'The trip version this edit is conditional on, from `get_trip` or from the previous edit. The edit is refused if the trip moved on since. Every edit answers with the new version, so a sequence of edits needs no read in between.')]
        public int $version,
        #[ApiProperty(description: 'Which day to act on, as published by `get_trip`. Required by every action except `add`. Stage identifiers survive edits but not a full replan.')]
        public ?string $stageId = null,
        #[ApiProperty(description: 'Where to insert, 0-based. `add` only; defaults to the end of the trip.')]
        public ?int $position = null,
        #[ApiProperty(description: 'Where to move the day to, 0-based. `move` only.')]
        public ?int $toIndex = null,
        #[ApiProperty(description: 'Where the day starts. `add` (required) and `update`.')]
        public ?Coordinate $startPoint = null,
        #[ApiProperty(description: 'Where the day ends. `add` (required) and `update`. Moving it also moves the start of the following day.')]
        public ?Coordinate $endPoint = null,
        #[ApiProperty(description: 'Free-text name for the day. `add` and `update`.')]
        public ?string $label = null,
        #[ApiProperty(description: 'Target distance in kilometres for the day, which splits it at that point instead of naming an end. `update` only.')]
        public ?float $distance = null,
    ) {
    }
}
