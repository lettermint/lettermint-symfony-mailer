<?php

namespace Lettermint\SymfonyMailer\Transport;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;

/** @internal */
final class PayloadBuilder
{
    private const BYPASS_HEADERS = [
        'from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'content-type',
        'content-transfer-encoding', 'content-disposition', 'mime-version', 'return-path',
        'idempotency-key', 'x-lm-options', 'x-lm-tag', 'x-tag',
    ];

    /** @param array<string, mixed> $defaults */
    public function __construct(private readonly array $defaults = [])
    {
        $this->validateOptions($defaults);
    }

    /** @return array<string, mixed> */
    public function build(Email $email, Envelope $envelope): array
    {
        $options = $this->defaults;
        if (null !== $header = $email->getHeaders()->get('X-LM-Options')) {
            try {
                $overrides = json_decode((string) $header->getBody(), true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidArgumentException('X-LM-Options must contain a JSON object.');
            }
            if (!is_array($overrides)) {
                throw new InvalidArgumentException('X-LM-Options must contain a JSON object.');
            }
            $this->validateOptions($overrides);
            $options = array_replace($options, $overrides);
            if (isset($overrides['settings'])) {
                $options['settings'] = array_replace($this->defaults['settings'] ?? [], $overrides['settings']);
            }
        }
        $payload = ['from' => $envelope->getSender()->toString(), 'subject' => $email->getSubject() ?? ''];
        $payload += $this->recipients($email, $envelope);
        if ([] === $payload['to']) {
            throw new InvalidArgumentException('Lettermint requires at least one To recipient in the envelope. CC-only and BCC-only messages are not supported.');
        }
        if ($email->getReplyTo()) {
            $payload['reply_to'] = array_map(static fn (Address $address): string => $address->toString(), $email->getReplyTo());
        }
        // Read the rendered MIME tree so Symfony's inline CID replacements reach the API.
        $this->readParts($this->renderBody($email), $payload);
        $headers = $metadata = [];
        $tag = null;
        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();
                continue;
            }
            if ($header instanceof TagHeader) {
                $tag = $header->getValue();
                continue;
            }
            if (in_array(strtolower($header->getName()), self::BYPASS_HEADERS, true)) {
                continue;
            }
            $headers[$header->getName()] = $header->getBodyAsString();
        }
        if (null === $tag && null !== $legacy = $email->getHeaders()->get('X-LM-Tag')) {
            $tag = (string) $legacy->getBody();
        }
        if (null !== $tag) {
            $payload['tag'] = $tag;
        }
        if ($headers) {
            $payload['headers'] = (object) $headers;
        }
        if ($metadata) {
            $payload['metadata'] = (object) $metadata;
        }

        return array_replace($payload, $options);
    }

    /** @return array<string, list<string>> */
    private function recipients(Email $email, Envelope $envelope): array
    {
        $groups = ['to' => $email->getTo(), 'cc' => $email->getCc(), 'bcc' => $email->getBcc()];
        $categories = [];
        foreach ($groups as $name => $addresses) {
            foreach ($addresses as $address) {
                $categories[$this->mailboxKey($address)] ??= [$name, $address];
            }
        }
        $result = ['to' => []];
        $seen = [];
        foreach ($envelope->getRecipients() as $address) {
            $key = $this->mailboxKey($address);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            [$category, $original] = $categories[$key] ?? ['to', $address];
            $result[$category][] = $original->toString();
        }

        return $result;
    }

    private function renderBody(Email $email): AbstractPart
    {
        if (null === $email->getTextBody() && null === $email->getHtmlBody()) {
            return $email->getBody();
        }
        // Email clones share attachment objects. Render separate parts to keep CID
        // replacement from changing the caller's attachments on repeated sends.
        $copy = new Email();
        $copy->text($email->getTextBody(), $email->getTextCharset() ?? 'utf-8');
        $copy->html($email->getHtmlBody(), $email->getHtmlCharset() ?? 'utf-8');
        foreach ($email->getAttachments() as $attachment) {
            $copy->addPart(clone $attachment);
        }

        return $copy->getBody();
    }

    private function mailboxKey(Address $address): string
    {
        $mailbox = $address->getEncodedAddress();
        $at = strrpos($mailbox, '@');

        return substr($mailbox, 0, $at).'@'.strtolower(substr($mailbox, $at + 1));
    }

    /** @param array<string, mixed> $payload */
    private function readParts(AbstractPart $part, array &$payload): void
    {
        if ($part instanceof DataPart) {
            $headers = $part->getPreparedHeaders();
            $contentType = clone $headers->get('Content-Type');
            if ($contentType instanceof \Symfony\Component\Mime\Header\ParameterizedHeader) {
                $parameters = $contentType->getParameters();
                unset($parameters['name']);
                $contentType->setParameters($parameters);
            }
            $attachment = [
                'filename' => $headers->getHeaderParameter('Content-Disposition', 'filename') ?? 'attachment',
                'content' => base64_encode($part->getBody()),
                'content_type' => $contentType->getBodyAsString(),
            ];
            if ($part->hasContentId()) {
                $attachment['content_id'] = $part->getContentId();
            }
            $payload['attachments'][] = $attachment;
        } elseif ($part instanceof AbstractMultipartPart) {
            foreach ($part->getParts() as $child) {
                $this->readParts($child, $payload);
            }
        } elseif ($part instanceof TextPart && in_array($part->getMediaSubtype(), ['plain', 'html'], true)) {
            $payload['plain' === $part->getMediaSubtype() ? 'text' : 'html'] = $part->getBody();
        } else {
            throw new InvalidArgumentException('The MIME message contains an unsupported body part.');
        }
    }

    /** @param array<string, mixed> $options */
    private function validateOptions(array $options): void
    {
        if (array_diff(array_keys($options), ['route', 'scheduled_at', 'settings', 'tag', 'tags'])) {
            throw new InvalidArgumentException('Unknown Lettermint message option.');
        }
        foreach (['route', 'scheduled_at', 'tag'] as $key) {
            if (array_key_exists($key, $options) && (!is_string($options[$key]) || '' === trim($options[$key]))) {
                throw new InvalidArgumentException('Route, scheduled_at, and tag must be non-empty strings.');
            }
        }
        if (array_key_exists('settings', $options)) {
            $settings = $options['settings'];
            if (!is_array($settings) || array_diff(array_keys($settings), ['track_opens', 'track_clicks', 'tls'])) {
                throw new InvalidArgumentException('Invalid Lettermint settings.');
            }
            foreach (['track_opens', 'track_clicks'] as $key) {
                if (array_key_exists($key, $settings) && !is_bool($settings[$key])) {
                    throw new InvalidArgumentException('Tracking settings must be booleans.');
                }
            }
            if (array_key_exists('tls', $settings) && !in_array($settings['tls'], ['opportunistic', 'enforced'], true)) {
                throw new InvalidArgumentException('TLS must be opportunistic or enforced.');
            }
        }
        if (array_key_exists('tags', $options)) {
            if (!is_array($options['tags']) || !array_is_list($options['tags'])) {
                throw new InvalidArgumentException('Tags must be a list of name/value pairs.');
            }
            foreach ($options['tags'] as $tag) {
                if (!is_array($tag) || 2 !== count($tag) || !isset($tag['name'], $tag['value']) || !is_string($tag['name']) || !is_string($tag['value'])) {
                    throw new InvalidArgumentException('Each tag must contain a string name and value.');
                }
            }
        }
    }
}
