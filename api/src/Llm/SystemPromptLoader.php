<?php

declare(strict_types=1);

namespace App\Llm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads versioned LLM system prompts from disk and substitutes placeholder variables.
 *
 * Prompt files live next to the source code (under `api/src/Llm/SystemPrompt/`) so they
 * are versioned with the codebase and reviewed alongside the prompt-engineering changes.
 *
 * Placeholders use the `{{variable_name}}` syntax and are replaced via simple
 * string substitution — no Twig dependency, no expression evaluation, no surprises.
 */
final readonly class SystemPromptLoader
{
    private const string EXTENSION = '.txt';

    private string $promptDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Llm/SystemPrompt')]
        string $promptDir,
    ) {
        $this->promptDir = rtrim($promptDir, \DIRECTORY_SEPARATOR);
    }

    /**
     * Loads a prompt by name (without the `.txt` extension) and substitutes
     * the provided placeholder values.
     *
     * Any placeholder left in the template after substitution is left as-is
     * (no exception thrown) so that callers may layer multiple substitution
     * passes if needed.
     *
     * @param non-empty-string     $promptName Filename without extension, e.g. `stage-analysis`.
     *                                         Must not contain path separators.
     * @param array<string,scalar> $variables  map of placeholder name → replacement value
     *
     * @throws \InvalidArgumentException if the prompt name is empty or contains separators
     * @throws \RuntimeException         if the prompt file cannot be found or read
     */
    public function load(string $promptName, array $variables = []): string
    {
        if ('' === $promptName) {
            throw new \InvalidArgumentException('Prompt name must not be empty.');
        }

        if (str_contains($promptName, '/') || str_contains($promptName, '\\') || str_contains($promptName, "\0") || '.' === $promptName || '..' === $promptName) {
            throw new \InvalidArgumentException(\sprintf('Prompt name "%s" must not contain path separators, null bytes, or be a relative-path reference.', $promptName));
        }

        $path = $this->promptDir.\DIRECTORY_SEPARATOR.$promptName.self::EXTENSION;

        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('System prompt "%s" not found at "%s".', $promptName, $path));
        }

        $template = @file_get_contents($path);

        if (false === $template) {
            throw new \RuntimeException(\sprintf('Failed to read system prompt "%s" at "%s".', $promptName, $path));
        }

        if ([] === $variables) {
            return $template;
        }

        $search = [];
        $replace = [];

        foreach ($variables as $name => $value) {
            $search[] = '{{'.$name.'}}';
            $replace[] = (string) $value;
        }

        // str_replace with arrays is sequential: each replacement is applied on the already-
        // substituted string. Variable values must not contain the {{…}} delimiter themselves.
        return str_replace($search, $replace, $template);
    }
}
