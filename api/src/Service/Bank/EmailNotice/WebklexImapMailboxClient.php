<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

use Webklex\PHPIMAP\ClientManager;

final class WebklexImapMailboxClient implements ImapMailboxClientInterface
{
    public function __construct(private readonly EmailNoticeTextNormalizer $normalizer) {}

    public function latest(array $settings, int $limit): array
    {
        $client = $this->client($settings);
        $client->connect();
        try {
            $folder = $this->folder($client, $settings);
            $messages = $folder->query()
                ->all()
                ->leaveUnread()
                ->fetchOrderDesc()
                ->limit(max(1, $limit))
                ->get();

            $out = [];
            foreach ($messages as $message) {
                $out[] = $this->toNoticeMessage($message, $settings);
            }
            return $out;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function toNoticeMessage(object $message, array $settings): BankEmailNoticeMessage
    {
        $html = (string) $message->getHTMLBody();
        $text = (string) $message->getTextBody();
        $rawBody = method_exists($message, 'getRawBody') ? (string) $message->getRawBody() : ($html !== '' ? $html : $text);
        $body = $text !== '' ? $text : ($html !== '' ? $html : $rawBody);

        return new BankEmailNoticeMessage(
            uid: (int) $message->getUid() ?: null,
            messageId: trim((string) $message->getMessageId()) ?: null,
            date: $this->messageDate($message),
            sender: MimeHeaderDecoder::decode((string) $message->getFrom()),
            subject: MimeHeaderDecoder::decode((string) $message->getSubject()),
            text: $this->normalizer->normalize($body),
            raw: $rawBody,
            authResults: $this->authenticationResults($message),
            allowForwarded: (bool) ($settings['allow_forwarded'] ?? false),
            forwardedFrom: trim((string) ($settings['forwarded_from'] ?? '')),
        );
    }

    public function test(array $settings): array
    {
        try {
            $client = $this->client($settings);
            $client->connect();
            $folders = [];
            foreach ($client->getFolders(false) as $folder) {
                $folders[] = (string) $folder->path;
            }
            $client->disconnect();
            return ['ok' => true, 'message' => 'IMAP připojení je funkční.', 'folders' => $folders];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function postProcess(array $settings, BankEmailNoticeMessage $message, string $kind): ?string
    {
        $action = (string) ($settings[$kind . '_action'] ?? 'none');
        if ($action === 'none' || $message->uid === null) {
            return null;
        }

        try {
            $client = $this->client($settings);
            $client->connect();
            $folder = $this->folder($client, $settings);
            $imapMessage = $folder->query()->leaveUnread()->getMessageByUid($message->uid);
            if ($action === 'mark_seen' && $kind === 'success') {
                $imapMessage->setFlag('Seen');
            } elseif ($action === 'add_flag') {
                $flag = (string) ($settings[$kind . '_flag'] ?? '');
                if ($flag !== '') {
                    $imapMessage->setFlag($flag);
                }
            } elseif ($action === 'move') {
                $target = (string) ($settings[$kind . '_move_folder'] ?? '');
                if ($target !== '') {
                    $imapMessage->move($target);
                }
            }
            $client->disconnect();
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function client(array $settings): \Webklex\PHPIMAP\Client
    {
        if (!class_exists(ClientManager::class)) {
            throw new \RuntimeException('Knihovna webklex/php-imap není nainstalovaná.');
        }
        $manager = new ClientManager([
            'default' => 'default',
            'accounts' => [],
            'options' => [
                'fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK,
                'sequence' => \Webklex\PHPIMAP\IMAP::ST_UID,
                'fetch_body' => true,
                'fetch_flags' => true,
                'message_key' => 'uid',
                'fetch_order' => 'desc',
                'rfc822' => true,
                'soft_fail' => true,
            ],
        ]);
        return $manager->make([
            'host' => (string) ($settings['host'] ?? ''),
            'port' => (int) ($settings['port'] ?? 993),
            'protocol' => 'imap',
            'encryption' => ($settings['encryption'] ?? 'ssl') === 'none' ? false : (string) ($settings['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($settings['validate_cert'] ?? true),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'authentication' => null,
            'rfc' => 'RFC822',
            'timeout' => 30,
            'extensions' => [],
        ]);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function folder(\Webklex\PHPIMAP\Client $client, array $settings): \Webklex\PHPIMAP\Folder
    {
        $path = trim((string) ($settings['folder'] ?? 'INBOX')) ?: 'INBOX';
        $folder = $client->getFolderByPath($path, false, true);
        if ($folder !== null) {
            return $folder;
        }

        $available = [];
        foreach ($client->getFolders(false, null, true) as $candidate) {
            $available[] = (string) $candidate->path;
            if ((string) $candidate->full_name === $path || (string) $candidate->name === $path) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'IMAP složka nebyla nalezena: ' . $path
            . ($available !== [] ? '. Dostupné složky: ' . implode(', ', $available) : '.')
        );
    }

    /**
     * Vytáhne hodnoty hlaviček Authentication-Results z raw hlaviček zprávy.
     * Pořadí v raw = shora dolů = nejnovější hop (přijímací server) první.
     *
     * @return list<string>
     */
    private function authenticationResults(object $message): array
    {
        try {
            $header = method_exists($message, 'getHeader') ? $message->getHeader() : null;
            $raw = is_object($header) && isset($header->raw) ? (string) $header->raw : '';
        } catch (\Throwable) {
            $raw = '';
        }
        if (trim($raw) === '') {
            return [];
        }
        // Hlavička může být zalomená na víc řádků (pokračování začíná mezerou/tabem).
        if (preg_match_all('/^Authentication-Results:[ \t]*(.*(?:\r?\n[ \t]+.*)*)/mi', $raw, $m) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $value) {
            $value = trim((string) preg_replace('/\s+/', ' ', $value));
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    private function messageDate(object $message): ?\DateTimeImmutable
    {
        try {
            $date = $message->getDate();
            if (is_object($date) && method_exists($date, 'toDate')) {
                $dt = $date->toDate();
                if ($dt instanceof \DateTimeInterface) {
                    return \DateTimeImmutable::createFromInterface($dt);
                }
            }
            $text = trim((string) $date);
            return $text !== '' ? new \DateTimeImmutable($text) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
