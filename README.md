# Lettermint Symfony Mailer bridge

Send email through the Lettermint API with Symfony Mailer.

- Composer package: `lettermint/symfony-mailer`
- Namespace: `Lettermint\SymfonyMailer`
- PHP 8.2 or later. Symfony Mailer 6.4, 7.4, or 8.x, with the PHP version required by that Symfony release.

The package is not yet published to Packagist.

## Local installation

Add a path repository to your application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../lettermint-symfony-mailer",
            "options": {"versions": {"lettermint/symfony-mailer": "0.1.0"}}
        }
    ],
    "require": {
        "lettermint/symfony-mailer": "^0.1"
    }
}
```

Run `composer update lettermint/symfony-mailer` in the application. Adjust the local path as required.

## Symfony application setup

Add the factory service to `config/services.yaml`:

```yaml
services:
    Lettermint\SymfonyMailer\Transport\LettermintTransportFactory:
        autoconfigure: false
        arguments:
            $dispatcher: '@?event_dispatcher'
            $client: '@http_client'
            $logger: '@?logger'
        tags: ['mailer.transport_factory']
```

Set the DSN in `.env.local`:

```dotenv
MAILER_DSN=lettermint+api://PROJECT_TOKEN@default
```

Use a project token. URL-encode the token if it contains reserved URL characters. Configure Symfony Mailer to read the DSN:

```yaml
# config/packages/mailer.yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
```

The factory registration is required. Installing this third-party package alone does not add its factory to Symfony's built-in factory list.

You can then use `MailerInterface` and `Email` as usual:

```php
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

function sendReceipt(MailerInterface $mailer): void
{
    $email = (new Email())
        ->from('Example <sender@example.com>')
        ->to('recipient@example.com')
        ->subject('Your receipt')
        ->text('Thank you for your order.')
        ->html('<p>Thank you for your order.</p>');

    $mailer->send($email);
}
```

## Standalone Mailer

```php
use Lettermint\SymfonyMailer\Transport\LettermintTransportFactory;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;

$registry = new Transport([new LettermintTransportFactory()]);
$transport = $registry->fromString('lettermint+api://'.rawurlencode($projectToken).'@default');
$mailer = new Mailer($transport);
$mailer->send($email);
```

Use this registry to resolve the custom DSN. `Transport::fromDsn()` uses Symfony's built-in factories and does not discover this package.

## Route and delivery settings

The DSN accepts `route`, `timeout`, `track_opens`, `track_clicks`, and `tls`:

```dotenv
MAILER_DSN=lettermint+api://PROJECT_TOKEN@default?route=transactional&timeout=15&track_opens=false&track_clicks=true&tls=enforced
```

`route` is a route slug. `timeout` is a positive number of seconds and limits both inactivity and total request duration. It defaults to 15. `tls` is `opportunistic` or `enforced`; it controls email delivery, not HTTPS certificate verification.

To override options for one email, add an `OptionsHeader`:

```php
use Lettermint\SymfonyMailer\Header\OptionsHeader;

$email->getHeaders()->add(new OptionsHeader([
    'route' => 'transactional',
    'scheduled_at' => '2030-01-15T09:00:00+00:00',
    'settings' => ['track_opens' => false, 'tls' => 'enforced'],
    'tags' => [['name' => 'campaign', 'value' => 'welcome']],
]));
```

The options header is removed from the delivered email. It supports `route`, `scheduled_at`, `settings`, `tag`, and `tags`. Per-email settings override the corresponding defaults. Other default settings remain active. Named tags replace the default list. Scheduling also accepts API-supported English date expressions; use an explicit time zone for absolute timestamps.

These options remain part of the email when it is serialized for Symfony Messenger. Set options and idempotency keys before the message enters the queue.

## Tags, metadata, and idempotency

```php
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;

$email->getHeaders()->add(new TagHeader('receipt'));
$email->getHeaders()->add(new MetadataHeader('order_id', '123'));
$email->getHeaders()->addTextHeader('Idempotency-Key', 'receipt-123');
```

`TagHeader` maps to the API's single `tag` field. If several are present, the last one wins, as in the Laravel driver. `X-LM-Tag` is a fallback. Use `OptionsHeader` for named `tags`. Each `MetadataHeader` adds one string entry to `metadata`.

The idempotency key goes into the HTTP request header, not the delivered email. The bridge does not generate content-based keys or retry requests. A custom injected HTTP client can have its own retry policy. Use the same explicit key when your application repeats a send.

## Recipients and attachments

The transport uses the envelope sender and recipients. It includes CC and BCC only when those addresses remain in the envelope. This supports Symfony's recipient overrides without sending to excluded addresses. Address matching uses mailbox values, not PHP object identity. Duplicate envelope recipients are sent once.

The API requires a non-empty `to` array. The bridge rejects CC-only and BCC-only envelopes before making an HTTP request. It does not move a hidden recipient into the visible To field.

Normal attachments, file attachments, stream attachments, and inline images are supported. The bridge encodes the original attachment bytes as base64, independent of MIME transfer encoding. It reads the rendered MIME body so inline `cid:` references match the attachment content IDs. MIME type parameters, such as a calendar method, are preserved.

Text and HTML emails are supported. Arbitrary raw MIME, signed/encrypted bodies, and unsupported MIME parts cannot be represented by the sending API.

## Responses and failures

Successful requests require HTTP 202 and a non-empty `message_id`. Calling `$transport->send($email)` returns a `SentMessage`; its `getMessageId()` returns the exact API ID. `MailerInterface::send()` returns no value. Use `SentMessageEvent` to read the ID in a Symfony application.

The API ID is a transport ID. It is not changed into an RFC Message-ID. This differs from the Laravel driver's `@lmta.net` suffix. To preserve a custom email Message-ID at the API, include both the `Message-ID` and `X-LM-Preserve-Message-ID: true` headers.

API failures raise `Lettermint\SymfonyMailer\Transport\ApiException`, a Symfony `TransportException`. It exposes `statusCode` and a token-redacted `responseBody`. Network failures raise `TransportException`. HTTP debug data is not attached to messages or exceptions because it can contain the project token. Redirects are disabled. Requests use the fixed HTTPS API endpoint.

## Development

```sh
composer install
composer validate --strict
composer test
composer analyse
composer format-check
composer audit
php examples/standalone.php
```

The standalone example uses a mock HTTP client by default. To send one real email, set `LETTERMINT_PROJECT_TOKEN`, `LETTERMINT_FROM`, `LETTERMINT_TO`, and `LETTERMINT_IDEMPOTENCY_KEY`, then run `php examples/standalone.php --send`.

The source contract test uses a payload accepted by the actual API's `MessageRules`, plus immediate and scheduled responses from `SendMailController`. The source record contains file and fixture hashes. To check the API checkout again:

```sh
python3 tools/verify_api_source.py /absolute/path/to/lettermint
```

This command also exports fresh controller responses and runs the API request rules against the sample payload. It does not send email or write to the database. It does not test domain ownership, authentication, or production delivery.

The design follows Symfony's [Resend bridge](https://github.com/symfony/resend-mailer), [MailerSend bridge](https://github.com/symfony/mailer-send-mailer), and [custom transport factory interface](https://symfony.com/doc/current/mailer.html#custom-transport-factories). The Laravel driver's options and message mapping were also reviewed. The bridge has no Laravel or Lettermint PHP SDK dependency.

See [.github/WORKFLOWS.md](.github/WORKFLOWS.md) for CI and release setup.
