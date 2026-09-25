<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Mcp;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\ConfirmationChallenge;
use App\Entity\User;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripShareRepositoryInterface;
use App\State\Mcp\ConfirmationStore;
use App\State\Mcp\McpConfirmationProcessor;
use App\State\Mcp\TripImpactSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The two-call shape of a destructive tool, and the four ways a token must not work.
 *
 * What is being pinned is narrow on purpose. The token proves that an impact summary was
 * produced and that the arguments did not move between the two calls — not that a human
 * agreed to anything, since the same agent makes both calls.
 */
final class McpConfirmationProcessorTest extends TestCase
{
    private const string TRIP = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $written;

    protected function setUp(): void
    {
        $this->written = new \ArrayObject();
    }

    #[Test]
    public function aFirstCallWritesNothingAndHandsBackAToken(): void
    {
        $answer = $this->processor()->process(null, $this->tool(), ['id' => self::TRIP], $this->call([]));

        self::assertInstanceOf(ConfirmationChallenge::class, $answer);
        self::assertNotSame('', $answer->confirmationToken);
        self::assertTrue($answer->confirmationRequired);
        self::assertSame('Delete this trip.', $answer->action);
        self::assertSame(self::TRIP, $answer->impact->tripId);
        self::assertCount(0, $this->written, 'The decorated processor ran: something was written on the call that only asks.');
    }

    #[Test]
    public function theSecondCallCarriesItOut(): void
    {
        $processor = $this->processor();
        $challenge = $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call([]));
        self::assertInstanceOf(ConfirmationChallenge::class, $challenge);

        $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call(['confirmationToken' => $challenge->confirmationToken]));

        self::assertCount(1, $this->written);
    }

    #[Test]
    public function aTokenIsSpentOnce(): void
    {
        $processor = $this->processor();
        $challenge = $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call([]));
        self::assertInstanceOf(ConfirmationChallenge::class, $challenge);

        $replay = $this->call(['confirmationToken' => $challenge->confirmationToken]);
        $processor->process(null, $this->tool(), ['id' => self::TRIP], $replay);

        $this->expectException(BadRequestHttpException::class);
        $processor->process(null, $this->tool(), ['id' => self::TRIP], $replay);
    }

    /**
     * The property the whole mechanism exists for: what the human was shown described these
     * arguments, so a token spent on others would confirm something nobody saw.
     */
    #[Test]
    public function aTokenDoesNotCoverDifferentArguments(): void
    {
        $processor = $this->processor();
        $challenge = $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call(['keepShareLink' => true]));
        self::assertInstanceOf(ConfirmationChallenge::class, $challenge);

        $this->expectException(BadRequestHttpException::class);
        $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call([
            'keepShareLink' => false,
            'confirmationToken' => $challenge->confirmationToken,
        ]));
    }

    /**
     * The other half of that: nothing obliges a client to re-serialise its arguments in the
     * order it used the first time, and a token refused for a re-ordering would send an agent
     * round a loop it cannot get out of.
     */
    #[Test]
    public function reorderingTheArgumentsIsNotChangingThem(): void
    {
        $processor = $this->processor();
        $challenge = $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call([
            'keepShareLink' => true,
            'reason' => 'obsolete',
        ]));
        self::assertInstanceOf(ConfirmationChallenge::class, $challenge);

        $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call([
            'reason' => 'obsolete',
            'confirmationToken' => $challenge->confirmationToken,
            'keepShareLink' => true,
        ]));

        self::assertCount(1, $this->written);
    }

    #[Test]
    public function aTokenDoesNotCoverAnotherTrip(): void
    {
        $processor = $this->processor();
        $challenge = $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call([]));
        self::assertInstanceOf(ConfirmationChallenge::class, $challenge);

        $this->expectException(BadRequestHttpException::class);
        $processor->process(null, $this->tool(), ['id' => '0199a1b2-c3d4-7e5f-8a9b-000000000000'], $this->call([
            'confirmationToken' => $challenge->confirmationToken,
        ]));
    }

    #[Test]
    public function aTokenDoesNotCoverAnotherTool(): void
    {
        $processor = $this->processor();
        $challenge = $processor->process(null, $this->tool(), ['id' => self::TRIP], $this->call([]));
        self::assertInstanceOf(ConfirmationChallenge::class, $challenge);

        $this->expectException(BadRequestHttpException::class);
        $processor->process(null, $this->tool('unshare_trip'), ['id' => self::TRIP], $this->call([
            'confirmationToken' => $challenge->confirmationToken,
        ]));
    }

    #[Test]
    public function anInventedTokenIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->processor()->process(null, $this->tool(), ['id' => self::TRIP], $this->call([
            'confirmationToken' => str_repeat('a', 32),
        ]));
    }

    /**
     * A model echoing the token back is free to mangle it, and the mangled string would have
     * become a cache key. The answer stays the documented one — 400 with a message telling the
     * agent to ask again — instead of whatever the cache backend throws.
     */
    #[Test]
    public function aMangledTokenIsRefusedLikeAnyOther(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->processor()->process(null, $this->tool(), ['id' => self::TRIP], $this->call([
            'confirmationToken' => '"{the token}"',
        ]));
    }

    /** A tool that does not declare the property is none of this decorator's business. */
    #[Test]
    public function anUndeclaredToolGoesStraightThrough(): void
    {
        $tool = new McpTool(name: 'get_trip', uriTemplate: '/trips/{id}/detail');

        $this->processor()->process(null, $tool, ['id' => self::TRIP], $this->call([]));

        self::assertCount(1, $this->written);
    }

    /**
     * On HTTP the caller is a person in an interface that already asked them; a second round
     * trip would be a regression for every web and mobile client.
     */
    #[Test]
    public function httpIsNeverAskedTwice(): void
    {
        $this->processor()->process(null, new Delete(uriTemplate: '/trips/{id}', extraProperties: [McpConfirmationProcessor::EXTRA_PROPERTY => 'Delete this trip.']), ['id' => self::TRIP], []);

        self::assertCount(1, $this->written);
    }

    private function processor(): McpConfirmationProcessor
    {
        $trips = $this->createStub(TripRequestRepositoryInterface::class);
        $trips->method('getRequest')->willReturn(null);
        $trips->method('getStages')->willReturn([]);

        $shares = $this->createStub(TripShareRepositoryInterface::class);
        $shares->method('findActiveByTrip')->willReturn(null);

        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new User('agent@example.com'), 'main'));

        return new McpConfirmationProcessor(
            $this->decorated(),
            new ConfirmationStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
            new TripImpactSummary($trips, $shares),
            $storage,
        );
    }

    /** @return ProcessorInterface<mixed, mixed> */
    private function decorated(): ProcessorInterface
    {
        return new readonly class ($this->written) implements ProcessorInterface {
            /** @param \ArrayObject<int, string> $written */
            public function __construct(private \ArrayObject $written)
            {
            }

            public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
            {
                $this->written[] = 'written';

                return $data;
            }
        };
    }

    private function tool(string $name = 'delete_trip'): McpTool
    {
        return new McpTool(
            name: $name,
            uriTemplate: '/trips/{id}',
            extraProperties: [McpConfirmationProcessor::EXTRA_PROPERTY => 'Delete this trip.'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{mcp_data: array<string, mixed>}
     */
    private function call(array $arguments): array
    {
        return ['mcp_data' => $arguments];
    }
}
