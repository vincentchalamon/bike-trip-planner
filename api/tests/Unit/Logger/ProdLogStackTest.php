<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logger;

use App\Entity\User;
use App\Logger\RedactingJsonFormatter;
use App\Logger\RedactionProcessor;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * NoEmailInLogsTest keeps addresses out of the application's own logger calls. This
 * covers the lines the framework writes, through the handler stack prod runs: the
 * security and request debug lines wait in the fingers_crossed buffer and are written
 * to stderr, as JSON, the moment an error lands.
 */
final class ProdLogStackTest extends TestCase
{
    private const string ADDRESS = 'rider@example.com';

    private const string SHARE_CODE = 'Ab3-_x9Z';

    #[Test]
    public function aFlushedBufferCarriesNoAddressAndNoCredential(): void
    {
        $stream = fopen('php://memory', 'w+');
        \assert(\is_resource($stream));
        $nested = new StreamHandler($stream, Level::Debug);
        $nested->setFormatter(new RedactingJsonFormatter());

        $main = new FingersCrossedHandler($nested, new ErrorLevelActivationStrategy(Level::Error), 50);

        $processor = new RedactionProcessor();
        $security = new Logger('security', [$main], [$processor]);
        $request = new Logger('request', [$main], [$processor]);

        // Symfony\Component\Security\Http\Authentication\AuthenticatorManager
        $token = new PostAuthenticationToken(new User(self::ADDRESS), 'api', ['ROLE_USER']);
        $security->info('Authenticator successful!', ['token' => $token, 'authenticator' => 'App\Security\JwtAuthenticator']);

        // Symfony\Component\HttpKernel\EventListener\RouterListener
        $request->info('Matched route "{route}".', [
            'route' => '_api_/s/{shortCode}{._format}_get',
            'route_parameters' => ['_route' => '_api_/s/{shortCode}{._format}_get', 'shortCode' => self::SHARE_CODE],
            'request_uri' => 'https://localhost/s/'.self::SHARE_CODE.'?email=rider%40example.com&signature=deadbeef',
            'method' => 'GET',
        ]);

        // Symfony\Component\HttpKernel\EventListener\ErrorListener, on a driver error
        // quoting the row, which also flushes the buffer.
        $exception = new BadRequestHttpException('Key (email)=('.self::ADDRESS.') already exists.');
        $request->error(\sprintf('Uncaught PHP Exception %s: "%s" at %s line %s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()), ['exception' => $exception]);

        rewind($stream);
        $written = (string) stream_get_contents($stream);

        self::assertStringContainsString('Authenticator successful!', $written, 'the buffer was not flushed: the test proves nothing');
        self::assertStringContainsString('Matched route', $written);
        self::assertStringNotContainsString(self::ADDRESS, $written);
        self::assertStringNotContainsString('rider%40example.com', $written);
        self::assertStringNotContainsString(self::SHARE_CODE, $written);
        self::assertStringNotContainsString('deadbeef', $written);
    }

    /**
     * The worker's `messenger:consume -vv` prints every info line to stdout; the
     * http_client ones carry each outbound URL, trip coordinates included.
     */
    #[Test]
    public function theProdConsoleHandlerLeavesOutgoingRequestsOut(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new MonologExtension());
        new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config'), 'prod')->load('packages/monolog.php');

        $console = null;
        foreach ($container->getExtensionConfig('monolog') as $config) {
            $console = ((array) ($config['handlers'] ?? []))['console'] ?? $console;
        }

        self::assertIsArray($console);
        self::assertContains('!http_client', (array) ($console['channels'] ?? []));
    }

    /** The stack above is only prod's if prod's stderr handlers do use that formatter. */
    #[Test]
    public function everyProdHandlerWritingJsonRedactsIt(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new MonologExtension());
        new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config'), 'prod')->load('packages/monolog.php');

        $streams = 0;
        foreach ($container->getExtensionConfig('monolog') as $config) {
            foreach ((array) ($config['handlers'] ?? []) as $name => $handler) {
                self::assertIsArray($handler);
                if ('stream' !== ($handler['type'] ?? null)) {
                    continue;
                }

                ++$streams;
                self::assertSame(RedactingJsonFormatter::class, $handler['formatter'] ?? null, \sprintf('prod handler "%s" writes without redaction', $name));
            }
        }

        self::assertGreaterThan(0, $streams);
    }
}
