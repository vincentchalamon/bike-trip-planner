<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\SystemPromptLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SystemPromptLoaderTest extends TestCase
{
    private string $tmpDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'system-prompt-loader-'.bin2hex(random_bytes(4));

        if (!mkdir($this->tmpDir, 0o755, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException(\sprintf('Failed to create tmp dir "%s".', $this->tmpDir));
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }

        foreach (glob($this->tmpDir.\DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tmpDir);
    }

    #[Test]
    public function loadReturnsRawTemplateWhenNoVariablesProvided(): void
    {
        $this->writePrompt('greeting', "Hello {{name}}, welcome to {{place}}.\n");

        $loader = new SystemPromptLoader($this->tmpDir);

        self::assertSame("Hello {{name}}, welcome to {{place}}.\n", $loader->load('greeting'));
    }

    #[Test]
    public function loadSubstitutesProvidedPlaceholders(): void
    {
        $this->writePrompt('greeting', 'Hello {{name}}, welcome to {{place}}.');

        $loader = new SystemPromptLoader($this->tmpDir);

        $output = $loader->load('greeting', ['name' => 'Alice', 'place' => 'Cluny']);

        self::assertSame('Hello Alice, welcome to Cluny.', $output);
    }

    #[Test]
    public function loadLeavesUnknownPlaceholdersUntouched(): void
    {
        $this->writePrompt('partial', 'Hello {{name}} on {{date}}.');

        $loader = new SystemPromptLoader($this->tmpDir);

        $output = $loader->load('partial', ['name' => 'Bob']);

        self::assertSame('Hello Bob on {{date}}.', $output);
    }

    #[Test]
    public function loadAcceptsScalarVariables(): void
    {
        $this->writePrompt('scalars', 'Stage {{n}} of {{total}} — distance: {{km}} km, sunny: {{sunny}}.');

        $loader = new SystemPromptLoader($this->tmpDir);

        $output = $loader->load('scalars', [
            'n' => 1,
            'total' => 3,
            'km' => 78.4,
            'sunny' => true,
        ]);

        self::assertSame('Stage 1 of 3 — distance: 78.4 km, sunny: 1.', $output);
    }

    #[Test]
    public function loadTrimsTrailingDirectorySeparatorOnConstruction(): void
    {
        $this->writePrompt('trimmed', 'ok');

        $loader = new SystemPromptLoader($this->tmpDir.\DIRECTORY_SEPARATOR);

        self::assertSame('ok', $loader->load('trimmed'));
    }

    #[Test]
    public function loadThrowsWhenPromptFileIsMissing(): void
    {
        $loader = new SystemPromptLoader($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('System prompt "missing" not found');

        $loader->load('missing');
    }

    #[Test]
    public function loadThrowsWhenPromptFileIsUnreadable(): void
    {
        if (0 === \posix_getuid()) {
            self::markTestSkipped('Cannot test file-permission denial when running as root.');
        }

        $path = $this->tmpDir.\DIRECTORY_SEPARATOR.'unreadable.txt';
        file_put_contents($path, 'content');
        chmod($path, 0o000);

        $loader = new SystemPromptLoader($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to read system prompt "unreadable"');

        try {
            $loader->load('unreadable');
        } finally {
            chmod($path, 0o644);
        }
    }

    #[Test]
    public function loadThrowsOnEmptyPromptName(): void
    {
        $loader = new SystemPromptLoader($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt name must not be empty.');

        // @phpstan-ignore argument.type (intentional: this test verifies the runtime guard against empty input)
        $loader->load('');
    }

    #[Test]
    public function loadRejectsForwardSlashInPromptName(): void
    {
        $loader = new SystemPromptLoader($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain path separators');

        $loader->load('../escape');
    }

    #[Test]
    public function loadRejectsBackslashInPromptName(): void
    {
        $loader = new SystemPromptLoader($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain path separators');

        $loader->load('escape\\windows');
    }

    #[Test]
    public function loadRejectsNullByteInPromptName(): void
    {
        $loader = new SystemPromptLoader($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain path separators');

        $loader->load("nul\0byte");
    }

    #[Test]
    public function shippedStageAnalysisPromptIsLoadable(): void
    {
        $loader = new SystemPromptLoader(__DIR__.'/../../../src/Llm/SystemPrompt');

        $output = $loader->load('stage-analysis', [
            'region' => 'France',
            'rider_profile' => 'gravel',
            'language' => 'fr',
            'date' => '2026-05-06',
        ]);

        self::assertStringNotContainsString('{{region}}', $output);
        self::assertStringNotContainsString('{{rider_profile}}', $output);
        self::assertStringContainsString('France', $output);
        self::assertStringContainsString('gravel', $output);
    }

    #[Test]
    public function shippedTripOverviewPromptIsLoadable(): void
    {
        $loader = new SystemPromptLoader(__DIR__.'/../../../src/Llm/SystemPrompt');

        $output = $loader->load('trip-overview', [
            'region' => 'EuroVelo',
            'rider_profile' => 'randonneur',
            'language' => 'fr',
            'date' => '2026-05-06',
        ]);

        self::assertStringNotContainsString('{{region}}', $output);
        self::assertStringNotContainsString('{{rider_profile}}', $output);
        self::assertStringContainsString('EuroVelo', $output);
        self::assertStringContainsString('randonneur', $output);
    }

    #[Test]
    public function shippedPromptsRespectTokenBudgets(): void
    {
        // Approximation: 1 token ~ 4 characters for English/French mixed text.
        // We allow generous headroom so the few-shot example keeps the prompts well under
        // the model's context window. The runtime <500/<800 token *output* budgets are
        // enforced by the model, not by the prompt length itself.
        $stage = file_get_contents(__DIR__.'/../../../src/Llm/SystemPrompt/stage-analysis.txt');
        $trip = file_get_contents(__DIR__.'/../../../src/Llm/SystemPrompt/trip-overview.txt');

        self::assertNotFalse($stage);
        self::assertNotFalse($trip);

        // Sanity bounds: must be long enough to be useful, short enough to fit comfortably
        // alongside user input in an 8B model's context window (~8k tokens).
        self::assertGreaterThan(500, mb_strlen($stage));
        self::assertGreaterThan(500, mb_strlen($trip));
        self::assertLessThan(8000, mb_strlen($stage));
        self::assertLessThan(8000, mb_strlen($trip));
    }

    private function writePrompt(string $name, string $contents): void
    {
        $path = $this->tmpDir.\DIRECTORY_SEPARATOR.$name.'.txt';

        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException(\sprintf('Failed to write prompt "%s".', $path));
        }
    }
}
