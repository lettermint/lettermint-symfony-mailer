<?php

namespace Lettermint\SymfonyMailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class LettermintApiTransport extends AbstractApiTransport
{
    private readonly PayloadBuilder $payloadBuilder;

    /** @param array<string, mixed> $options Default route and message settings. */
    public function __construct(
        #[\SensitiveParameter] private readonly string $projectToken,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        array $options = [],
        private readonly float $timeout = 15,
    ) {
        if ('' === trim($projectToken) || trim($projectToken) !== $projectToken || preg_match('/[\x00-\x1f\x7f]/', $projectToken)) {
            throw new InvalidArgumentException('A valid project token is required.');
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be a positive number of seconds.');
        }
        $this->payloadBuilder = new PayloadBuilder($options);
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return 'lettermint+api://default';
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['transport' => (string) $this, 'timeout' => $this->timeout];
    }

    protected function doSend(SentMessage $message): void
    {
        // HTTP debug output can contain the project token. Do not attach it to messages.
        $this->doSendHttp($message);
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $payload = $this->payloadBuilder->build($email, $envelope);
        $headers = ['x-lettermint-token' => $this->projectToken, 'accept' => 'application/json', 'user-agent' => 'lettermint-symfony-mailer/0.1.0'];
        if (null !== $key = $email->getHeaders()->get('Idempotency-Key')) {
            $headers['Idempotency-Key'] = $key->getBodyAsString();
        }
        try {
            $response = $this->client->request('POST', 'https://api.lettermint.co/v1/send', [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            // Do not retain an HTTP exception that can contain request credentials.
            throw new TransportException('Could not reach the Lettermint API.');
        }
        $safeBody = str_replace($this->projectToken, '[REDACTED]', $body);
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $safeBody = json_encode($this->redact($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new ApiException('The Lettermint API returned an invalid JSON response.', $status, $safeBody);
        }
        if (202 !== $status) {
            throw new ApiException(sprintf('The Lettermint API rejected the email (HTTP %d).', $status), $status, $safeBody);
        }
        if (!is_array($data) || !isset($data['message_id']) || !is_string($data['message_id']) || '' === $data['message_id']) {
            throw new ApiException('The Lettermint API response has no valid message_id.', $status, $safeBody);
        }
        // Symfony stores a transport ID here; it does not require an RFC Message-ID.
        $sentMessage->setMessageId($data['message_id']);

        return $response;
    }

    private function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace($this->projectToken, '[REDACTED]', $value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[is_string($key) ? str_replace($this->projectToken, '[REDACTED]', $key) : $key] = $this->redact($item);
            }

            return $result;
        }

        return $value;
    }
}
