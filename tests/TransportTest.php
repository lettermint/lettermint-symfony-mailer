<?php

namespace Lettermint\SymfonyMailer\Tests;

use Lettermint\SymfonyMailer\Header\OptionsHeader;
use Lettermint\SymfonyMailer\Transport\ApiException;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException as HttpException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class TransportTest extends TestCase
{
    protected function email(): Email
    {
        return (new Email())->from(new Address('sender@example.com', 'Sender'))->to('to@example.com')->subject('Hello')->text('Text body')->html('<p>HTML body</p>');
    }

    public function testPayloadAuthenticationAndMessageId(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('https://api.lettermint.co/v1/send', $url);
            self::assertContains('x-lettermint-token: test-token', $options['headers']);
            self::assertContains('Idempotency-Key: receipt-123', $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(15.0, $options['max_duration']);
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('"Sender" <sender@example.com>', $body['from']);
            self::assertSame(['to@example.com'], $body['to']);
            self::assertSame(['"Copy" <cc@example.com>'], $body['cc']);
            self::assertSame(['bcc@example.com'], $body['bcc']);
            self::assertSame(['reply@example.com', 'reply2@example.com'], $body['reply_to']);
            self::assertSame('Hello', $body['subject']);
            self::assertSame('Text body', $body['text']);
            self::assertSame('<p>HTML body</p>', $body['html']);
            self::assertSame(['X-Custom' => 'custom'], $body['headers']);
            self::assertSame(['order_id' => '123'], $body['metadata']);
            self::assertSame('receipt', $body['tag']);
            self::assertSame('transactional', $body['route']);
            self::assertSame('tomorrow at 9am', $body['scheduled_at']);
            self::assertSame(['track_opens' => false, 'track_clicks' => true, 'tls' => 'enforced'], $body['settings']);
            self::assertSame([['name' => 'campaign', 'value' => 'launch']], $body['tags']);

            return new MockResponse('{"message_id":"api-id","status":"pending"}', ['http_code' => 202, 'debug' => 'x-lettermint-token: test-token']);
        });
        $email = $this->email()->cc(new Address('cc@example.com', 'Copy'))->bcc('bcc@example.com')->replyTo('reply@example.com', 'reply2@example.com');
        $email->getHeaders()->add(new MetadataHeader('order_id', '123'));
        $email->getHeaders()->add(new TagHeader('receipt'));
        $email->getHeaders()->addTextHeader('X-LM-Tag', 'fallback');
        $email->getHeaders()->addTextHeader('Idempotency-Key', 'receipt-123');
        $email->getHeaders()->addTextHeader('X-Custom', 'custom');
        $email->getHeaders()->add(new OptionsHeader(['scheduled_at' => 'tomorrow at 9am', 'settings' => ['track_opens' => false], 'tags' => [['name' => 'campaign', 'value' => 'launch']]]));
        $transport = new LettermintApiTransport('test-token', $client, options: ['route' => 'transactional', 'settings' => ['track_opens' => true, 'track_clicks' => true, 'tls' => 'enforced']]);
        $sent = $transport->send($email);
        self::assertSame('api-id', $sent->getMessageId());
        self::assertSame('', $sent->getDebug());
        self::assertSame('lettermint+api://default', (string) $transport);
    }

    public function testEnvelopeOverridesRemoveOriginalRecipientsAndKeepBccPrivate(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            $body = json_decode($options['body'], true);
            self::assertSame(['sink@example.com'], $body['to']);
            self::assertSame(['hidden@example.com'], $body['bcc']);
            self::assertArrayNotHasKey('cc', $body);
            self::assertStringNotContainsString('to@example.com', $options['body']);
            self::assertStringNotContainsString('copy@example.com', $options['body']);
            self::assertSame('override@example.com', $body['from']);

            return new MockResponse('{"message_id":"id"}', ['http_code' => 202]);
        });
        $email = $this->email()->cc('copy@example.com')->bcc('hidden@example.com');
        $envelope = new Envelope(new Address('override@example.com'), [new Address('sink@example.com'), new Address('hidden@example.com'), new Address('sink@example.com')]);
        (new LettermintApiTransport('token', $client))->send($email, $envelope);
    }

    public function testEnvelopeAddressEqualityDoesNotDependOnObjectIdentity(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            $body = json_decode($options['body'], true);
            self::assertSame(['to@example.com'], $body['to']);
            self::assertSame(['"Copy" <cc@example.com>'], $body['cc']);
            self::assertSame(['hidden@example.com'], $body['bcc']);

            return new MockResponse('{"message_id":"id"}', ['http_code' => 202]);
        });
        $email = $this->email()->cc(new Address('cc@example.com', 'Copy'))->bcc('hidden@example.com');
        $envelope = new Envelope(new Address('sender@example.com'), [new Address('to@example.com'), new Address('cc@example.com'), new Address('hidden@example.com')]);
        (new LettermintApiTransport('token', $client))->send($email, $envelope);
    }

    public function testBccOnlyEnvelopeFailsBeforeHttpRequest(): void
    {
        $client = new MockHttpClient();
        $this->expectException(InvalidArgumentException::class);
        try {
            (new LettermintApiTransport('token', $client))->send($this->email()->bcc('hidden@example.com'), new Envelope(new Address('sender@example.com'), [new Address('hidden@example.com')]));
        } finally {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testAttachmentEncodingAndInlineCid(): void
    {
        $bytes = "binary\0\xff\r\n";
        $client = new MockHttpClient(function ($method, $url, $options) use ($bytes) {
            $body = json_decode($options['body'], true);
            $byName = array_column($body['attachments'], null, 'filename');
            self::assertSame($bytes, base64_decode($byName['data.bin']['content'], true));
            self::assertSame('application/octet-stream', $byName['data.bin']['content_type']);
            self::assertSame('calendar body', base64_decode($byName['invite.ics']['content'], true));
            self::assertStringContainsString('method=REQUEST', $byName['invite.ics']['content_type']);
            self::assertSame('image bytes', base64_decode($byName['logo.png']['content'], true));
            self::assertStringContainsString('cid:'.$byName['logo.png']['content_id'], $body['html']);
            self::assertStringNotContainsString('cid:logo.png', $body['html']);

            return new MockResponse('{"message_id":"id"}', ['http_code' => 202]);
        });
        $calendar = new DataPart('calendar body', 'invite.ics', 'text/calendar', 'quoted-printable');
        $calendar->getHeaders()->addParameterizedHeader('Content-Type', 'text/calendar', ['method' => 'REQUEST']);
        $email = $this->email()->html('<img src="cid:logo.png">')->addPart(new DataPart($bytes, 'data.bin', 'application/octet-stream', '8bit'))->addPart($calendar)->embed('image bytes', 'logo.png', 'image/png');
        (new LettermintApiTransport('token', $client))->send($email);
    }

    public function testFileAndStreamAttachmentsPreserveBytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lm-attachment-');
        file_put_contents($path, "file bytes\r\n");
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, 'stream bytes');
        $client = new MockHttpClient(function ($method, $url, $options) {
            $body = json_decode($options['body'], true);
            self::assertSame("file bytes\r\n", base64_decode($body['attachments'][0]['content'], true));
            self::assertSame('stream bytes', base64_decode($body['attachments'][1]['content'], true));

            return new MockResponse('{"message_id":"id"}', ['http_code' => 202]);
        });
        try {
            $email = $this->email()->attachFromPath($path, 'file.txt', 'text/plain')->attach($stream, 'stream.txt', 'text/plain');
            (new LettermintApiTransport('token', $client))->send($email);
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testBccMailboxDomainComparisonIsCaseInsensitive(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            $body = json_decode($options['body'], true);
            self::assertSame(['to@example.com'], $body['to']);
            self::assertSame(['hidden@EXAMPLE.com'], $body['bcc']);

            return new MockResponse('{"message_id":"id"}', ['http_code' => 202]);
        });
        $email = $this->email()->bcc('hidden@EXAMPLE.com');
        $envelope = new Envelope(new Address('sender@example.com'), [new Address('to@example.com'), new Address('hidden@example.com')]);
        (new LettermintApiTransport('token', $client))->send($email, $envelope);
    }

    public function testRepeatedInlineSendsKeepMatchingContentIds(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            $body = json_decode($options['body'], true);
            self::assertStringContainsString('cid:'.$body['attachments'][0]['content_id'], $body['html']);

            return new MockResponse('{"message_id":"id"}', ['http_code' => 202]);
        });
        $email = $this->email()->html('<img src="cid:logo.png">')->embed('image bytes', 'logo.png', 'image/png');
        $transport = new LettermintApiTransport('token', $client);
        $transport->send($email);
        $transport->send($email);
        self::assertSame('logo.png', $email->getAttachments()[0]->getName());
    }

    public function testMessagesDoNotShareOptionsOrAttachments(): void
    {
        $requests = [];
        $client = new MockHttpClient(function ($method, $url, $options) use (&$requests) {
            $requests[] = json_decode($options['body'], true);

            return new MockResponse('{"message_id":"id"}', ['http_code' => 202]);
        });
        $first = $this->email()->attach('attachment data', 'file.txt');
        $first->getHeaders()->add(new MetadataHeader('order', '1'));
        $first->getHeaders()->add(new OptionsHeader(['scheduled_at' => 'tomorrow', 'settings' => ['track_opens' => false]]));
        $transport = new LettermintApiTransport('token', $client, options: ['settings' => ['track_opens' => true]]);
        $transport->send($first);
        $transport->send($this->email());
        self::assertArrayNotHasKey('scheduled_at', $requests[1]);
        self::assertArrayNotHasKey('metadata', $requests[1]);
        self::assertArrayNotHasKey('attachments', $requests[1]);
        self::assertTrue($requests[1]['settings']['track_opens']);
    }

    #[DataProvider('failureResponses')]
    public function testApiFailuresAreNotRetried(int $status, string $body): void
    {
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
        try {
            (new LettermintApiTransport('test-token', $client))->send($this->email());
            self::fail('Expected an API exception.');
        } catch (ApiException $e) {
            self::assertSame($status, $e->statusCode);
            self::assertStringNotContainsString('test-token', $e->responseBody);
            self::assertNull($e->getPrevious());
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public static function failureResponses(): iterable
    {
        yield [401, '{"message":"test\\u002Dtoken"}'];
        yield [422, '{"errors":{"to":["Required"]}}'];
        yield [429, '{"message":"rate limited"}'];
        yield [500, 'test-token server error'];
        yield [302, 'redirect'];
        yield [202, 'not JSON'];
        yield [202, '{}'];
        yield [202, '{"message_id":12}'];
        yield [200, '{"message_id":"id"}'];
    }

    public function testNetworkExceptionDoesNotExposeToken(): void
    {
        $client = new MockHttpClient(static function () { throw new HttpException('test-token'); });
        try {
            (new LettermintApiTransport('test-token', $client))->send($this->email());
            self::fail('Expected a transport exception.');
        } catch (TransportException $e) {
            self::assertStringNotContainsString('test-token', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsFailBeforeSend(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LettermintApiTransport('token', new MockHttpClient(), options: $options);
    }

    public static function invalidOptions(): iterable
    {
        yield [['from' => 'override@example.com']];
        yield [['settings' => ['tls' => 'disabled']]];
        yield [['settings' => ['track_opens' => 'false']]];
        yield [['scheduled_at' => null]];
        yield [['tags' => ['invalid']]];
    }

    public function testOptionsHeaderSurvivesSerialization(): void
    {
        $email = $this->email();
        $email->getHeaders()->add(new OptionsHeader(['scheduled_at' => 'tomorrow', 'tags' => [['name' => 'type', 'value' => 'receipt']]]));
        $copy = unserialize(serialize($email));
        self::assertSame($email->getHeaders()->get('X-LM-Options')->getBody(), $copy->getHeaders()->get('X-LM-Options')->getBody());
    }
}
