<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sentry;

use App\Sentry\EventScrubber;
use App\Sentry\ExceptionFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\ExceptionDataBag;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\State\Hub;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class EventScrubberTest extends TestCase
{
    /**
     * What the SDK's RequestIntegration attaches to a 5xx of POST /auth/verify.
     */
    private function eventOfAFailedVerify(): Event
    {
        $event = Event::createEvent(EventId::generate());
        $event->setRequest([
            'url' => 'https://example.test/s/Ab3-_x9Z?email=rider%40example.com',
            'method' => 'POST',
            'query_string' => 'email=rider%40example.com',
            'data' => ['token' => '0123abcd'],
            'cookies' => ['refresh_token' => 'r'],
            'headers' => ['Referer' => ['https://example.test/s/Ab3-_x9Z'], 'Authorization' => ['[Filtered]']],
        ]);

        return $event;
    }

    #[Test]
    public function keepsTheRouteAndDropsWhatTheRequestCarried(): void
    {
        $request = new EventScrubber()($this->eventOfAFailedVerify())->getRequest();

        self::assertSame([
            'url' => 'https://example.test/s/[redacted]',
            'method' => 'POST',
            'headers' => ['Referer' => ['https://example.test/s/[redacted]'], 'Authorization' => ['[Filtered]']],
        ], $request);
    }

    #[Test]
    public function redactsAddressesInExceptionsMessagesAndBreadcrumbs(): void
    {
        $event = Event::createEvent(EventId::generate());
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('Key (email)=(rider@example.com) already exists.'))]);
        $event->setMessage('Mail to %s failed', ['rider@example.com'], 'Mail to rider@example.com failed');
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'GET /auth/verify/0123abcd', ['url' => 'https://example.test/s/Ab3-_x9Z'])]);

        $event = new EventScrubber()($event);

        self::assertSame('Key (email)=([email]) already exists.', $event->getExceptions()[0]->getValue());
        self::assertSame(['[email]'], $event->getMessageParams());
        self::assertSame('Mail to [email] failed', $event->getMessageFormatted());
        self::assertSame('GET /auth/verify/[redacted]', $event->getBreadcrumbs()[0]->getMessage());
        self::assertSame(['url' => 'https://example.test/s/[redacted]'], $event->getBreadcrumbs()[0]->getMetadata());
    }

    #[Test]
    public function aTransactionLosesTheSecretsOfItsNameAndItsTraceUrl(): void
    {
        $event = Event::createTransaction(EventId::generate());
        $event->setTransaction('GET https://example.test/s/Ab3-_x9Z');
        $event->setContext('trace', ['trace_id' => 't', 'span_id' => 's', 'data' => ['http.url' => 'https://example.test/auth/verify/0123abcd?x=1']]);

        $event = new EventScrubber()($event);

        self::assertSame('GET https://example.test/s/[redacted]', $event->getTransaction());
        self::assertSame(['trace_id' => 't', 'span_id' => 's', 'data' => ['http.url' => 'https://example.test/auth/verify/[redacted]']], $event->getContexts()['trace']);
    }

    /** What sentry-symfony's traceable http client records for an outbound call. */
    #[Test]
    public function anOutboundSpanKeepsItsHostAndPathButNotItsQuery(): void
    {
        $span = new Hub()->startTransaction(TransactionContext::make())->startChild(SpanContext::make()
            ->setOp('http.client')
            ->setDescription('GET https://api.open-meteo.com/v1/forecast')
            ->setData(['http.url' => 'https://api.open-meteo.com/v1/forecast', 'http.query' => 'latitude=45.18&longitude=5.72', 'http.request.method' => 'GET']));
        $event = Event::createTransaction(EventId::generate());
        $event->setSpans([$span]);

        new EventScrubber()($event);

        self::assertSame([
            'http.url' => 'https://api.open-meteo.com/v1/forecast',
            'http.query' => '[redacted]',
            'http.request.method' => 'GET',
        ], $span->getData());
        self::assertSame('GET https://api.open-meteo.com/v1/forecast', $span->getDescription());
    }

    /** Only an http span's description is a URL: in SQL a `?` is a placeholder. */
    #[Test]
    public function aDatabaseSpanKeepsItsStatement(): void
    {
        $span = new Hub()->startTransaction(TransactionContext::make())->startChild(SpanContext::make()
            ->setOp('db.sql.query')
            ->setDescription('SELECT * FROM "user" WHERE email = ? AND id = ?'));
        $event = Event::createTransaction(EventId::generate());
        $event->setSpans([$span]);

        new EventScrubber()($event);

        self::assertSame('SELECT * FROM "user" WHERE email = ? AND id = ?', $span->getDescription());
    }

    #[Test]
    public function theExceptionFilterForwardsOnlyScrubbedEvents(): void
    {
        $event = new ExceptionFilter()($this->eventOfAFailedVerify(), EventHint::fromArray(['exception' => new \RuntimeException('boom')]));

        self::assertNotNull($event);
        self::assertArrayNotHasKey('data', $event->getRequest());
        self::assertArrayNotHasKey('query_string', $event->getRequest());
    }

    #[Test]
    public function prodNeitherReadsTheBodyNorSendsAnUnscrubbedEvent(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new SentryExtension());
        new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 3).'/config'), 'prod')->load('packages/sentry.php');

        $options = [];
        foreach ($container->getExtensionConfig('sentry') as $config) {
            $options = [...$options, ...(array) ($config['options'] ?? [])];
        }

        self::assertSame('none', $options['max_request_body_size'] ?? null);
        self::assertSame(ExceptionFilter::class, $options['before_send'] ?? null);
        self::assertSame(EventScrubber::class, $options['before_send_transaction'] ?? null);
    }
}
