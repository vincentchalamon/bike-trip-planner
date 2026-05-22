<?php

declare(strict_types=1);

namespace App\Command;

use App\Llm\ChatActionInterpreter;
use App\Llm\LlmClientInterface;
use App\Llm\Poc\PocPromptSuite;
use App\Llm\Poc\Tool\AddWaypointTool;
use App\Llm\Poc\Tool\AdjustDistanceTool;
use App\Llm\Poc\Tool\ChangeAccommodationTool;
use App\Llm\Poc\Tool\ChangeRouteTool;
use App\Llm\Poc\Tool\MergeStagesTool;
use App\Llm\Poc\Tool\SplitStageTool;
use App\Llm\SystemPromptLoader;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:poc:tool-calling',
    description: 'POC: benchmark symfony/ai-agent tool calling vs the current JSON-envelope strategy on llama3.2:3b.',
)]
final class PocToolCallingCommand extends Command
{
    private const string TOOL_CALLING_SYSTEM_PROMPT = <<<'PROMPT'
        You assist a bikepacking trip planner. When the rider asks you to modify the trip,
        invoke the matching tool with the right parameters. When the rider asks an
        information question (greetings, terminology, weather, encouragement…), reply
        with a short French sentence and do NOT call any tool.

        Stage numbers are 1-based. Stay strictly within the listed tools.
        PROMPT;

    private readonly string $model;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly LlmClientInterface $llmClient,
        private readonly ChatActionInterpreter $interpreter,
        private readonly SystemPromptLoader $promptLoader,
        #[Autowire(env: 'default::OLLAMA_DIALOGUE_MODEL')]
        ?string $model = null,
    ) {
        $this->model = $model ?: 'llama3.2:3b';
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of cases to run (debug).')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Run each prompt N times to assess determinism.', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cases = PocPromptSuite::cases();
        if (null !== $limit = $input->getOption('limit')) {
            $cases = \array_slice($cases, 0, (int) $limit);
        }

        $repeat = max(1, (int) $input->getOption('repeat'));

        $toolbox = new Toolbox([
            new SplitStageTool(),
            new MergeStagesTool(),
            new AddWaypointTool(),
            new ChangeAccommodationTool(),
            new AdjustDistanceTool(),
            new ChangeRouteTool(),
        ]);
        $tools = array_values($toolbox->getTools());
        $dialoguePrompt = $this->promptLoader->load('dialogue', [
            'region' => 'France',
            'profile' => 'gravel',
            'language' => 'fr',
            'date' => date('Y-m-d'),
        ]);

        $rows = [];
        $aiMatches = 0;
        $legacyMatches = 0;
        $aiErrors = 0;
        $legacyErrors = 0;
        $expanded = [];
        foreach ($cases as $case) {
            for ($i = 0; $i < $repeat; ++$i) {
                $expanded[] = $case;
            }
        }

        $total = \count($expanded);

        $io->section('Pass 1/2 — symfony/ai tool calling');
        $aiOutcomes = [];
        foreach ($expanded as $idx => $case) {
            $io->writeln(\sprintf('<comment>[%d/%d]</comment> %s', $idx + 1, $total, $case['prompt']));
            $aiOutcomes[$idx] = $this->runSymfonyAi($case['prompt'], $tools);
        }

        $io->section('Pass 2/2 — legacy JSON envelope');
        $legacyOutcomes = [];
        foreach ($expanded as $idx => $case) {
            $io->writeln(\sprintf('<comment>[%d/%d]</comment> %s', $idx + 1, $total, $case['prompt']));
            $legacyOutcomes[$idx] = $this->runLegacy($case['prompt'], $dialoguePrompt);
        }

        foreach ($expanded as $idx => $case) {
            $aiOutcome = $aiOutcomes[$idx];
            $legacyOutcome = $legacyOutcomes[$idx];

            $aiMatch = $this->matchesExpected($case, $aiOutcome);
            $legacyMatch = $this->matchesExpected($case, $legacyOutcome);

            if (str_starts_with($aiOutcome['action'] ?? '', '__error__')) {
                ++$aiErrors;
            }

            if (str_starts_with($legacyOutcome['action'] ?? '', '__error__')) {
                ++$legacyErrors;
            }

            if ($aiMatch) {
                ++$aiMatches;
            }

            if ($legacyMatch) {
                ++$legacyMatches;
            }

            $rows[] = [
                'prompt' => $case['prompt'],
                'expected' => $this->formatAction($case['expected_action'], $case['expected_params']),
                'ai' => $this->formatAction($aiOutcome['action'], $aiOutcome['params']).' '.($aiMatch ? '✓' : '✗').' ('.\sprintf('%.0f', $aiOutcome['ms']).'ms)',
                'ai_raw' => self::truncate($aiOutcome['raw'], 100),
                'legacy' => $this->formatAction($legacyOutcome['action'], $legacyOutcome['params']).' '.($legacyMatch ? '✓' : '✗').' ('.\sprintf('%.0f', $legacyOutcome['ms']).'ms)',
                'legacy_raw' => self::truncate($legacyOutcome['raw'], 100),
            ];
        }

        $io->section('Per-prompt results');
        $io->table(
            ['Prompt', 'Expected', 'symfony/ai (tool)', 'Legacy (JSON envelope)'],
            array_map(static fn (array $r): array => [
                self::truncate($r['prompt'], 50),
                $r['expected'],
                $r['ai'],
                $r['legacy'],
            ], $rows),
        );

        $io->section('Raw responses (truncated)');
        foreach ($rows as $r) {
            $io->writeln(\sprintf('<info>%s</info>', $r['prompt']));
            $io->writeln('  AI  : '.$r['ai_raw']);
            $io->writeln('  LEG : '.$r['legacy_raw']);
        }

        $io->section('Summary');
        $io->definitionList(
            ['Total runs' => (string) $total],
            ['symfony/ai matches' => \sprintf('%d / %d (%.0f%%)', $aiMatches, $total, $aiMatches / $total * 100)],
            ['Legacy matches' => \sprintf('%d / %d (%.0f%%)', $legacyMatches, $total, $legacyMatches / $total * 100)],
            ['symfony/ai errors' => (string) $aiErrors],
            ['Legacy errors' => (string) $legacyErrors],
        );

        return Command::SUCCESS;
    }

    /**
     * @param list<Tool> $tools
     *
     * @return array{action: ?string, params: array<string, mixed>, ms: float, raw: string}
     */
    private function runSymfonyAi(string $prompt, array $tools): array
    {
        $messages = new MessageBag(
            Message::forSystem(self::TOOL_CALLING_SYSTEM_PROMPT),
            Message::ofUser($prompt),
        );

        $start = microtime(true);
        try {
            $result = $this->platform->invoke($this->model, $messages, ['tools' => $tools, 'keep_alive' => '30m']);

            try {
                $toolCalls = $result->asToolCalls();
                $ms = (microtime(true) - $start) * 1000;
                if ([] === $toolCalls) {
                    return ['action' => null, 'params' => [], 'ms' => $ms, 'raw' => ''];
                }

                $call = $toolCalls[0];

                return [
                    'action' => $call->getName(),
                    'params' => $call->getArguments(),
                    'ms' => $ms,
                    'raw' => json_encode(['name' => $call->getName(), 'args' => $call->getArguments()], \JSON_THROW_ON_ERROR),
                ];
            } catch (UnexpectedResultTypeException) {
                // Model returned plain text (no tool call) — treat as "info" intent
                $text = $result->asText();
                $ms = (microtime(true) - $start) * 1000;

                return ['action' => null, 'params' => [], 'ms' => $ms, 'raw' => $text];
            }
        } catch (\Throwable $throwable) {
            $ms = (microtime(true) - $start) * 1000;

            return ['action' => '__error__', 'params' => [], 'ms' => $ms, 'raw' => $throwable->getMessage()];
        }
    }

    /**
     * @return array{action: ?string, params: array<string, mixed>, ms: float, raw: string}
     */
    private function runLegacy(string $prompt, string $systemPrompt): array
    {
        $start = microtime(true);
        try {
            $payload = $this->llmClient->chat(
                $this->model,
                [['role' => 'user', 'content' => $prompt]],
                $systemPrompt,
                ['num_predict' => 600, 'keep_alive' => '30m'],
            );
            $ms = (microtime(true) - $start) * 1000;

            if (null === $payload) {
                return ['action' => '__error__', 'params' => [], 'ms' => $ms, 'raw' => 'Ollama disabled'];
            }

            $message = $payload['message'] ?? null;
            $messageContent = \is_array($message) ? ($message['content'] ?? null) : null;
            $rawResponse = $payload['response'] ?? null;
            $content = '';
            if (\is_string($messageContent)) {
                $content = $messageContent;
            } elseif (\is_string($rawResponse)) {
                $content = $rawResponse;
            }

            $action = $this->interpreter->interpret($content);

            return [
                'action' => 'info' === $action->action ? null : $action->action,
                'params' => $action->params,
                'ms' => $ms,
                'raw' => $content,
            ];
        } catch (\Throwable $throwable) {
            $ms = (microtime(true) - $start) * 1000;

            return ['action' => '__error__', 'params' => [], 'ms' => $ms, 'raw' => $throwable->getMessage()];
        }
    }

    /**
     * @param array{prompt: string, expected_action: string|null, expected_params: array<string, mixed>} $case
     * @param array{action: ?string, params: array<string, mixed>, ms: float, raw: string}               $outcome
     */
    private function matchesExpected(array $case, array $outcome): bool
    {
        // info / no-tool case: both must be null/info
        if (null === $case['expected_action']) {
            return null === $outcome['action'];
        }

        if ($outcome['action'] !== $case['expected_action']) {
            return false;
        }

        return $this->paramsMatch($case['expected_action'], $case['expected_params'], $outcome['params']);
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function paramsMatch(string $action, array $expected, array $actual): bool
    {
        return match ($action) {
            'split_stage' => self::asInt($actual['stage'] ?? null) === self::asInt($expected['stage'] ?? null),
            'merge_stages' => $this->mergeStagesMatch(\is_array($expected['stages'] ?? null) ? $expected['stages'] : [], $actual),
            'add_waypoint' => null !== self::asString($actual['name'] ?? null)
                && 0 === strcasecmp(trim((string) self::asString($actual['name'])), (string) self::asString($expected['name'] ?? null))
                && (!isset($expected['stage']) || self::asInt($actual['stage'] ?? null) === self::asInt($expected['stage'])),
            'change_accommodation' => self::asInt($actual['stage'] ?? null) === self::asInt($expected['stage'] ?? null)
                && null !== self::asString($actual['type'] ?? null)
                && 0 === strcasecmp((string) self::asString($actual['type']), (string) self::asString($expected['type'] ?? null)),
            'adjust_distance' => self::asInt($actual['stage'] ?? null) === self::asInt($expected['stage'] ?? null)
                && null !== self::asFloat($actual['km'] ?? null)
                && abs((float) self::asFloat($actual['km']) - (float) (self::asFloat($expected['km'] ?? null) ?? 0.0)) < 0.01,
            'change_route' => true,
            default => false,
        };
    }

    /**
     * @param array<int|string, mixed> $expectedStages
     * @param array<string, mixed>     $actual
     */
    private function mergeStagesMatch(array $expectedStages, array $actual): bool
    {
        $expected = [self::asInt($expectedStages[0] ?? 0) ?? 0, self::asInt($expectedStages[1] ?? 0) ?? 0];

        // The legacy interpreter returns {stages: [a, b]}.
        $actualStages = $actual['stages'] ?? null;
        if (\is_array($actualStages) && 2 === \count($actualStages)) {
            $got = [self::asInt($actualStages[0] ?? null) ?? 0, self::asInt($actualStages[1] ?? null) ?? 0];
            sort($got);
            sort($expected);

            return $got === $expected;
        }

        // The tool-calling variant returns {firstStage: a, secondStage: b}.
        if (isset($actual['firstStage'], $actual['secondStage'])) {
            $got = [self::asInt($actual['firstStage']) ?? 0, self::asInt($actual['secondStage']) ?? 0];
            sort($got);
            sort($expected);

            return $got === $expected;
        }

        return false;
    }

    private static function asInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && '' !== $value && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }

    private static function asFloat(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        if (\is_string($value) && '' !== $value && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private static function asString(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function formatAction(?string $action, array $params): string
    {
        if (null === $action) {
            return '(info)';
        }

        if ([] === $params) {
            return $action;
        }

        try {
            $encoded = json_encode($params, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $encoded = '<encode-error>';
        }

        return $action.' '.$encoded;
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }
}
