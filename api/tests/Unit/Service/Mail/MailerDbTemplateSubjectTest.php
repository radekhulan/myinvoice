<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailerDbTemplateSubjectTest extends TestCase
{
    public function testDbTemplateSubjectIsRenderedWithTemplateVariables(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn([
            'id' => 1,
            'code' => 'invoice_send',
            'locale' => 'cs',
            'subject' => 'Faktura {{ invoice.varsymbol }}',
            'body_html' => '<p>Faktura {{ invoice.varsymbol }}</p>',
            'body_text' => 'Faktura {{ invoice.varsymbol }}',
            'updated_at' => '2026-06-02 12:00:00',
        ]);

        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'from_email' => 'noreply@example.test',
                    'from_name' => 'MyInvoice',
                    'dkim' => ['enabled' => false],
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
        );

        $transport = new CapturingTransport();
        $transportProperty = new \ReflectionProperty(Mailer::class, 'transport');
        $transportProperty->setValue($mailer, $transport);

        $mailer->sendTemplate('invoice_send', 'cs', ['client@example.test'], [
            'invoice' => ['varsymbol' => '2605001'],
            'supplier' => [
                'id' => 1,
                'company_name' => 'Dodavatel s.r.o.',
                'display_name' => 'Dodavatel',
                'email_branding_enabled' => false,
            ],
        ]);

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame('Faktura 2605001', $transport->message->getSubject());
        self::assertStringContainsString('Faktura 2605001', $transport->message->getTextBody() ?? '');
        self::assertStringContainsString('Faktura 2605001', $transport->message->getHtmlBody() ?? '');
    }
}

final class CapturingTransport implements TransportInterface
{
    public ?RawMessage $message = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->message = $message;
        return null;
    }

    public function __toString(): string
    {
        return 'capturing://test';
    }
}
