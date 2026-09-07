<?php

namespace Lettermint\SymfonyMailer\Transport;

use Symfony\Component\Mailer\Exception\TransportException;

/** An API failure with a status code and token-redacted response text. */
final class ApiException extends TransportException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $responseBody,
    ) {
        parent::__construct($message, $statusCode);
    }
}
