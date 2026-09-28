<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Pins the console contract of every app command: what cron entries, the Makefile and the
 * runbooks type must keep parsing the same way, whatever style the command is written in.
 */
final class CommandInputDefinitionTest extends KernelTestCase
{
    #[Test]
    public function accessRequestList(): void
    {
        self::assertSame([
            'description' => 'List verified access requests',
            'arguments' => [],
            'options' => [
                'before' => [null, 'required', null, 'Filter requests verified before this date (ISO 8601)'],
                'after' => [null, 'required', null, 'Filter requests verified after this date (ISO 8601)'],
                'email' => [null, 'required', null, 'Filter by email pattern (substring match)'],
                'page' => [null, 'required', '1', 'Page number (default: 1)'],
                'limit' => [null, 'required', '20', 'Results per page (default: 20)'],
            ],
        ], $this->definitionOf('app:access-request:list'));
    }

    #[Test]
    public function createUser(): void
    {
        self::assertSame([
            'description' => 'Create a new user and send an invitation email',
            'arguments' => [
                'email' => ['required', null, 'Email address of the new user'],
            ],
            'options' => [
                'locale' => ['l', 'required', 'fr', 'Locale for the invitation email'],
                'no-invite' => [null, 'none', false, 'Create the user without an invitation magic link or email (e.g. so a caller can drive /auth/request-link itself)'],
            ],
        ], $this->definitionOf('app:create-user'));
    }

    #[Test]
    public function messengerClear(): void
    {
        self::assertSame([
            'description' => 'Clear messages from one or more Messenger transports',
            'arguments' => [
                'transports' => ['optional array', [], 'Transport names to clear'],
            ],
            'options' => [
                'all' => [null, 'none', false, 'Clear all configured transports'],
            ],
        ], $this->definitionOf('app:messenger:clear'));
    }

    #[Test]
    public function notifyWeatherSafety(): void
    {
        self::assertSame([
            'description' => 'Push weather + safety notifications for the stages ridden on a target day',
            'arguments' => [],
            'options' => [
                'day' => [null, 'required', 'today', 'Target day: today | tomorrow | an ISO date (Y-m-d)'],
            ],
        ], $this->definitionOf('app:notifications:weather-safety'));
    }

    #[Test]
    public function notifyZoneOpened(): void
    {
        self::assertSame([
            'description' => 'Announce a newly opened reference zone to opted-in users',
            'arguments' => [
                'slug' => ['required', null, 'Zone slug as promoted in osm.zones (e.g. corse)'],
            ],
            'options' => [
                'name' => [null, 'required', null, 'Display name (defaults to the osm.zones name for the slug)'],
            ],
        ], $this->definitionOf('app:notifications:zone-opened'));
    }

    #[Test]
    public function purgeExpiredTokens(): void
    {
        self::assertSame([
            'description' => 'Purge expired refresh tokens from abandoned sessions',
            'arguments' => [],
            'options' => [],
        ], $this->definitionOf('app:purge-expired-tokens'));
    }

    #[Test]
    public function purgeIdempotencyKeys(): void
    {
        self::assertSame([
            'description' => 'Delete idempotency keys older than the retention window',
            'arguments' => [],
            'options' => [],
        ], $this->definitionOf('app:idempotency:purge'));
    }

    /**
     * @return array{description: string, arguments: array<string, list<mixed>>, options: array<string, list<mixed>>}
     */
    private function definitionOf(string $name): array
    {
        $command = new Application(self::bootKernel())->find($name);
        $definition = $command->getNativeDefinition();

        return [
            'description' => $command->getDescription(),
            'arguments' => array_map(static fn (InputArgument $argument): array => [
                ($argument->isRequired() ? 'required' : 'optional').($argument->isArray() ? ' array' : ''),
                $argument->getDefault(),
                $argument->getDescription(),
            ], $definition->getArguments()),
            'options' => array_map(static fn (InputOption $option): array => [
                $option->getShortcut(),
                match (true) {
                    $option->isValueRequired() => 'required',
                    $option->isValueOptional() => 'optional',
                    default => 'none',
                }.($option->isArray() ? ' array' : '').($option->isNegatable() ? ' negatable' : ''),
                $option->getDefault(),
                $option->getDescription(),
            ], $definition->getOptions()),
        ];
    }
}
