<?php

declare(strict_types=1);

/**
 * Connecteur Microsoft Graph — boite Outlook (ex: cv@colorado.ma).
 * Authentification : Client Credentials (application Azure AD).
 */
final class OutlookClient
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const PROCESSED_FOLDER = 'CVs-Traites';

    private const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'doc'];
    private const CV_KEYWORDS = ['cv', 'curriculum', 'resume', 'candidature', 'candidat', 'application', 'profil'];

    private ?string $accessToken = null;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mailbox
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $tenantId = trim((string) $config->get('AZURE_TENANT_ID', ''));
        $clientId = trim((string) $config->get('AZURE_CLIENT_ID', ''));
        $clientSecret = trim((string) $config->get('AZURE_CLIENT_SECRET', ''));
        $mailbox = trim((string) $config->get('OUTLOOK_MAILBOX', ''));

        $missing = [];
        foreach ([
            'AZURE_TENANT_ID' => $tenantId,
            'AZURE_CLIENT_ID' => $clientId,
            'AZURE_CLIENT_SECRET' => $clientSecret,
            'OUTLOOK_MAILBOX' => $mailbox,
        ] as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new HttpException(500, 'Variables Outlook manquantes: ' . implode(', ', $missing));
        }

        return new self($tenantId, $clientId, $clientSecret, $mailbox);
    }

    public function mailbox(): string
    {
        return $this->mailbox;
    }

    public function maxMessages(Config $config): int
    {
        return max(1, min(100, (int) ($config->get('OUTLOOK_MAX_MESSAGES', '50') ?? 50)));
    }

    public function testConnection(): array
    {
        try {
            $this->getToken();
            $messages = $this->listUnreadMessagesWithAttachments(1);

            return [
                'status' => 'ok',
                'token_acquired' => true,
                'mailbox' => $this->mailbox,
                'unread_with_attachments_sample' => count($messages),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'token_acquired' => false,
                'mailbox' => $this->mailbox,
            ];
        }
    }

    /**
     * @param array<string, true> $alreadyProcessed
     * @return list<array<string, mixed>>
     */
    public function fetchCvAttachments(array $alreadyProcessed, int $maxMessages): array
    {
        $results = [];
        $processedFolderId = null;

        foreach ($this->listUnreadMessagesWithAttachments($maxMessages) as $message) {
            $messageId = (string) ($message['id'] ?? '');
            if ($messageId === '' || isset($alreadyProcessed[$messageId])) {
                continue;
            }

            $sender = $message['from']['emailAddress'] ?? [];
            $senderEmail = (string) ($sender['address'] ?? '');
            $senderName = (string) ($sender['name'] ?? '');
            $subject = (string) ($message['subject'] ?? '');
            $receivedAt = (string) ($message['receivedDateTime'] ?? '');
            $bodyPreview = (string) ($message['bodyPreview'] ?? '');

            $foundCv = false;
            foreach ($this->listAttachments($messageId) as $attachment) {
                $filename = (string) ($attachment['name'] ?? '');
                $contentType = (string) ($attachment['contentType'] ?? '');
                if (!$this->isCvAttachment($filename, $contentType, $subject)) {
                    continue;
                }

                $bytes = $this->downloadAttachment($messageId, (string) ($attachment['id'] ?? ''), $attachment);
                if ($bytes === '') {
                    continue;
                }

                $foundCv = true;
                $results[] = [
                    'message_id' => $messageId,
                    'filename' => $filename,
                    'content_type' => $contentType,
                    'content_bytes' => $bytes,
                    'sender_email' => $senderEmail,
                    'sender_name' => $senderName,
                    'subject' => $subject,
                    'received_at' => $receivedAt,
                    'body_preview' => $bodyPreview,
                ];
            }

            if ($foundCv) {
                try {
                    $this->markAsRead($messageId);
                    if ($processedFolderId === null) {
                        $processedFolderId = $this->getOrCreateFolder(self::PROCESSED_FOLDER);
                    }
                    if ($processedFolderId !== null) {
                        $this->moveToFolder($messageId, $processedFolderId);
                    }
                } catch (Throwable) {
                    // Post-traitement non bloquant.
                }
            }
        }

        return $results;
    }

    private function isCvAttachment(string $filename, string $contentType, string $subject): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return false;
        }

        $contentType = strtolower($contentType);
        $allowedTypes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            'application/octet-stream',
            '',
        ];
        if (!in_array($contentType, $allowedTypes, true)) {
            return false;
        }

        $combined = strtolower($filename . ' ' . $subject);
        foreach (self::CV_KEYWORDS as $keyword) {
            if (str_contains($combined, $keyword)) {
                return true;
            }
        }

        return in_array($ext, ['pdf', 'docx'], true);
    }

    /** @return list<array<string, mixed>> */
    private function listUnreadMessagesWithAttachments(int $maxResults): array
    {
        $endpoint = '/users/' . rawurlencode($this->mailbox) . '/mailFolders/inbox/messages';
        $data = $this->graphGet($endpoint, [
            '$filter' => 'hasAttachments eq true and isRead eq false',
            '$top' => (string) min($maxResults, 50),
            '$select' => 'id,subject,from,receivedDateTime,bodyPreview,hasAttachments,isRead',
            '$orderby' => 'receivedDateTime desc',
        ]);

        return is_array($data['value'] ?? null) ? $data['value'] : [];
    }

    /** @return list<array<string, mixed>> */
    private function listAttachments(string $messageId): array
    {
        $endpoint = '/users/' . rawurlencode($this->mailbox) . '/messages/' . rawurlencode($messageId) . '/attachments';
        $data = $this->graphGet($endpoint, [
            '$select' => 'id,name,contentType,size,contentBytes',
        ]);

        return is_array($data['value'] ?? null) ? $data['value'] : [];
    }

    /** @param array<string, mixed> $attachmentMeta */
    private function downloadAttachment(string $messageId, string $attachmentId, array $attachmentMeta): string
    {
        if (!empty($attachmentMeta['contentBytes'])) {
            $decoded = base64_decode((string) $attachmentMeta['contentBytes'], true);

            return $decoded !== false ? $decoded : '';
        }

        $endpoint = '/users/' . rawurlencode($this->mailbox)
            . '/messages/' . rawurlencode($messageId)
            . '/attachments/' . rawurlencode($attachmentId);
        $data = $this->graphGet($endpoint);

        if (empty($data['contentBytes'])) {
            return '';
        }

        $decoded = base64_decode((string) $data['contentBytes'], true);

        return $decoded !== false ? $decoded : '';
    }

    private function markAsRead(string $messageId): void
    {
        $endpoint = '/users/' . rawurlencode($this->mailbox) . '/messages/' . rawurlencode($messageId);
        $this->graphPatch($endpoint, ['isRead' => true]);
    }

    private function moveToFolder(string $messageId, string $destinationFolderId): void
    {
        $endpoint = '/users/' . rawurlencode($this->mailbox) . '/messages/' . rawurlencode($messageId) . '/move';
        $this->graphPost($endpoint, ['destinationId' => $destinationFolderId]);
    }

    private function getOrCreateFolder(string $folderName): ?string
    {
        $endpoint = '/users/' . rawurlencode($this->mailbox) . '/mailFolders';
        $data = $this->graphGet($endpoint);
        foreach ($data['value'] ?? [] as $folder) {
            if (strcasecmp((string) ($folder['displayName'] ?? ''), $folderName) === 0) {
                return (string) ($folder['id'] ?? '');
            }
        }

        $created = $this->graphPost($endpoint, ['displayName' => $folderName]);

        return isset($created['id']) ? (string) $created['id'] : null;
    }

    private function getToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $url = 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        $response = $this->httpRequest('POST', $url, $body, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $decoded = json_decode($response['body'], true);
        if (($response['status'] ?? 0) !== 200 || empty($decoded['access_token'])) {
            $error = (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'Token Azure AD invalide');

            throw new RuntimeException('Authentification Microsoft Graph echouee: ' . $error);
        }

        $this->accessToken = (string) $decoded['access_token'];

        return $this->accessToken;
    }

    /** @param array<string, string> $query */
    private function graphGet(string $endpoint, array $query = []): array
    {
        $url = self::GRAPH_BASE . $endpoint;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->httpRequest('GET', $url, null, $this->authHeaders());
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            throw new RuntimeException('Graph API GET ' . $endpoint . ' HTTP ' . ($response['status'] ?? 0) . ': ' . substr($response['body'], 0, 240));
        }

        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function graphPatch(string $endpoint, array $payload): void
    {
        $url = self::GRAPH_BASE . $endpoint;
        $response = $this->httpRequest('PATCH', $url, json_encode($payload, JSON_UNESCAPED_UNICODE), $this->authHeaders());
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            throw new RuntimeException('Graph API PATCH echoue HTTP ' . ($response['status'] ?? 0));
        }
    }

    /** @param array<string, mixed> $payload */
    private function graphPost(string $endpoint, array $payload): array
    {
        $url = self::GRAPH_BASE . $endpoint;
        $response = $this->httpRequest('POST', $url, json_encode($payload, JSON_UNESCAPED_UNICODE), $this->authHeaders());
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            throw new RuntimeException('Graph API POST echoue HTTP ' . ($response['status'] ?? 0) . ': ' . substr($response['body'], 0, 240));
        }

        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function authHeaders(): array
    {
        return [
            'Authorization: Bearer ' . $this->getToken(),
            'Content-Type: application/json',
        ];
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,body:string,headers:string}
     */
    private function httpRequest(string $method, string $url, ?string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            if ($response === false) {
                throw new RuntimeException('Requete HTTP Outlook echouee.');
            }

            return [
                'status' => $status,
                'headers' => substr($response, 0, $headerSize),
                'body' => substr($response, $headerSize),
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return [
            'status' => $status,
            'headers' => isset($http_response_header) ? implode("\r\n", $http_response_header) : '',
            'body' => $response === false ? '' : $response,
        ];
    }
}
