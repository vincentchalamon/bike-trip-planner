<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Swaps the mailer for one whose SMTP server rejects the recipient, quoting it the
 * way a real rejection does.
 */
trait FailingMailerTrait
{
    private function failTheMailer(): void
    {
        self::getContainer()->set('mailer.mailer', new class () implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 <rider@example.com>: Recipient address rejected".', 550);
            }
        });
    }
}
