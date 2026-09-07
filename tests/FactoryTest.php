<?php

namespace Lettermint\SymfonyMailer\Tests;

use Lettermint\SymfonyMailer\Transport\LettermintTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class FactoryTest extends TestCase
{
    public function testDsnFactoryAndStandaloneRegistration(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            self::assertContains('x-lettermint-token: token/with+symbols', $options['headers']);
            self::assertSame(2.5, $options['timeout']);
            $body = json_decode($options['body'], true);
            self::assertSame('transactional', $body['route']);
            self::assertSame(['track_opens' => false, 'track_clicks' => true, 'tls' => 'enforced'], $body['settings']);

            return new MockResponse('{"message_id":"id"}', ['http_code' => 202]);
        });
        $registry = new Transport([new LettermintTransportFactory(client: $client)]);
        $transport = $registry->fromString('lettermint+api://token%2Fwith%2Bsymbols@default?route=transactional&timeout=2.5&track_opens=false&track_clicks=true&tls=enforced');
        self::assertSame('id', $transport->send((new Email())->from('a@example.com')->to('b@example.com')->subject('Test')->text('Hello'))->getMessageId());
    }

    #[DataProvider('invalidDsns')]
    public function testInvalidDsn(string $dsn): void
    {
        $this->expectException(\Symfony\Component\Mailer\Exception\ExceptionInterface::class);
        (new LettermintTransportFactory(client: new MockHttpClient()))->create(Dsn::fromString($dsn));
    }

    public static function invalidDsns(): iterable
    {
        yield ['smtp://token@default'];
        yield ['lettermint+api://token@evil.example'];
        yield ['lettermint+api://token:password@default'];
        yield ['lettermint+api://token@default:443'];
        yield ['lettermint+api://token@default?timeout=0'];
        yield ['lettermint+api://token@default?track_opens=maybe'];
        yield ['lettermint+api://token@default?tls=disabled'];
    }
}
