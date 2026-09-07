<?php

namespace Lettermint\SymfonyMailer\Tests;

use Lettermint\SymfonyMailer\Header\OptionsHeader;
use Lettermint\SymfonyMailer\Transport\LettermintApiTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;

final class ApiContractTest extends TestCase
{
    public function testRequestMatchesPayloadValidatedByActualApiRules(): void
    {
        $expected = json_decode(file_get_contents(__DIR__.'/Fixtures/request.json'), true, flags: JSON_THROW_ON_ERROR);
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/api-source.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($fixture['request_validated']);
        self::assertSame(['POST'], $fixture['route']['methods']);
        $client = new MockHttpClient(function ($method, $url, $options) use ($expected, $fixture) {
            self::assertSame('POST', $method);
            self::assertSame('https://api.lettermint.co/'.$fixture['route']['uri'], $url);
            self::assertEquals($expected, json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR));
            self::assertIsObject(json_decode($options['body'])->metadata);
            self::assertIsObject(json_decode($options['body'])->headers);
            foreach (array_keys($expected) as $field) {
                self::assertContains($field, $fixture['request_fields']);
            }

            return new MockResponse(json_encode($fixture['scheduled']), ['http_code' => 202]);
        });
        $email = (new Email())->from('sender@example.com')->to('recipient@example.com')->subject('API contract test')->text('Contract body')->attach('Contract attachment', 'test.txt', 'text/plain');
        $email->getHeaders()->add(new MetadataHeader('order_id', '123'));
        $email->getHeaders()->add(new TagHeader('receipt'));
        $email->getHeaders()->addTextHeader('X-Contract', 'yes');
        $email->getHeaders()->add(new OptionsHeader(array_intersect_key($expected, array_flip(['route', 'scheduled_at', 'settings', 'tags']))));
        self::assertSame($fixture['scheduled']['message_id'], (new LettermintApiTransport('token', $client))->send($email)->getMessageId());
    }

    #[DataProvider('responseKinds')]
    public function testActualControllerResponse(string $kind): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/Fixtures/api-source.json'), true, flags: JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(new MockResponse(json_encode($fixtures[$kind]), ['http_code' => 202]));
        $email = (new Email())->from('sender@example.com')->to('recipient@example.com')->subject('Test')->text('Hello');
        self::assertSame($fixtures[$kind]['message_id'], (new LettermintApiTransport('token', $client))->send($email)->getMessageId());
    }

    public static function responseKinds(): iterable
    {
        yield ['immediate'];
        yield ['scheduled'];
    }
}
