<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Optional operator webhook. Payloads contain event names and scope labels only,
 * never secret values. Delivery failures are swallowed so a webhook outage
 * cannot block rotation.
 */
class WebhookNotifier
{
    public function __construct(private readonly ?string $url) {}

    public function notify(string $event, array $fields): void
    {
        if ($this->url === null || $this->url === '') {
            return;
        }

        $payload = json_encode([
            'event'  => $event,
            'at'     => gmdate('c'),
            'fields' => $fields,
        ]);
        if (!is_string($payload)) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => 3,
                'ignore_errors' => true,
            ],
        ]);

        try {
            @file_get_contents($this->url, false, $context);
        } catch (\Throwable $e) {
            // Notification must not affect the rotation that triggered it.
        }
    }
}
