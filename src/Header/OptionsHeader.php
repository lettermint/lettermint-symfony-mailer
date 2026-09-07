<?php

namespace Lettermint\SymfonyMailer\Header;

use Symfony\Component\Mime\Header\UnstructuredHeader;

/** Per-message API options. This header is not sent to recipients. */
final class OptionsHeader extends UnstructuredHeader
{
    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        parent::__construct('X-LM-Options', json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
