<?php

declare(strict_types=1);

namespace App\Sentry;

use App\Logger\LogRedactor;
use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Takes what Sentry collects on its own out of every event before it leaves.
 *
 * `send_default_pii: false` does not cover the request: the SDK's RequestIntegration
 * attaches the full URL, the query string and (unless `max_request_body_size` is
 * `none`) the decoded body to every event, the 5xx of `/auth/verify` included, whose
 * body is the token. Transactions carry the same request, plus the URL in their trace
 * data. This keeps method, path and the sanitized headers, with the secret segments
 * of the path redacted, and runs the exception messages, the event message and the
 * breadcrumbs through {@see LogRedactor} (a driver error quotes the row it choked on).
 *
 * Wired as `before_send` (behind {@see ExceptionFilter}) and `before_send_transaction`.
 */
final class EventScrubber
{
    public function __invoke(Event $event): Event
    {
        $request = $event->getRequest();
        if ([] !== $request) {
            unset($request['query_string'], $request['data'], $request['cookies'], $request['env']);
            if (isset($request['url']) && \is_string($request['url'])) {
                $request['url'] = LogRedactor::url($request['url']);
            }

            $event->setRequest(LogRedactor::array($request));
        }

        $message = $event->getMessage();
        if (null !== $message) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                LogRedactor::text($message),
                array_map(static fn (mixed $param): mixed => \is_string($param) ? LogRedactor::text($param) : $param, $event->getMessageParams()),
                null === $formatted ? null : LogRedactor::text($formatted),
            );
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(LogRedactor::text($exception->getValue()));
        }

        $event->setBreadcrumb(array_map($this->breadcrumb(...), $event->getBreadcrumbs()));

        $transaction = $event->getTransaction();
        if (null !== $transaction) {
            $event->setTransaction(LogRedactor::url($transaction));
        }

        $trace = $event->getContexts()['trace'] ?? null;
        if (\is_array($trace) && isset($trace['data']) && \is_array($trace['data'])) {
            if (isset($trace['data']['http.url']) && \is_string($trace['data']['http.url'])) {
                $trace['data']['http.url'] = LogRedactor::url($trace['data']['http.url']);
            }

            $event->setContext('trace', LogRedactor::array($trace));
        }

        // The http_client spans of a transaction keep the outbound query apart
        // (`http.query`): trip coordinates for Open-Meteo and Nominatim, an API key
        // elsewhere. A span's data can only be merged into, not unset.
        foreach ($event->getSpans() as $span) {
            $data = $span->getData();
            $redacted = array_intersect_key(['http.query' => LogRedactor::REDACTED, 'http.fragment' => LogRedactor::REDACTED], $data);
            if (isset($data['http.url']) && \is_string($data['http.url'])) {
                $redacted['http.url'] = LogRedactor::url($data['http.url']);
            }

            $span->setData($redacted);
            // Only an http span's description is a URL; a DB span's is SQL, where a
            // `?` is a placeholder, not the start of a query string.
            $description = $span->getDescription();
            if (null !== $description) {
                $span->setDescription(str_starts_with((string) $span->getOp(), 'http.') ? LogRedactor::url($description) : LogRedactor::text($description));
            }
        }

        return $event;
    }

    private function breadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();
        if (null !== $message) {
            $breadcrumb = $breadcrumb->withMessage(LogRedactor::text($message));
        }

        foreach (LogRedactor::array($breadcrumb->getMetadata()) as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string) $name, $value);
        }

        return $breadcrumb;
    }
}
