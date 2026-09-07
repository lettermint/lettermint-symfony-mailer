<?php

namespace Lettermint\SymfonyMailer\Tests;

use Lettermint\SymfonyMailer\Transport\LettermintTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class RegistrationTest extends TestCase
{
    public function testDocumentedServiceRegistrationAndMailerEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(MessageEvent::class, function () use (&$events) { $events[] = 'before'; });
        $dispatcher->addListener(SentMessageEvent::class, function () use (&$events) { $events[] = 'after'; });
        $client = new MockHttpClient(new MockResponse('{"message_id":"id"}', ['http_code' => 202]));
        $container = new ContainerBuilder();
        $container->register('http_client', MockHttpClient::class)->setSynthetic(true);
        $container->register('event_dispatcher', EventDispatcher::class)->setSynthetic(true);
        (new YamlFileLoader($container, new FileLocator(__DIR__.'/../examples')))->load('services.yaml');
        $container->getDefinition(LettermintTransportFactory::class)->setPublic(true);
        self::assertArrayHasKey(LettermintTransportFactory::class, $container->findTaggedServiceIds('mailer.transport_factory'));
        $container->compile();
        $container->set('http_client', $client);
        $container->set('event_dispatcher', $dispatcher);
        $transport = $container->get(LettermintTransportFactory::class)->create(Dsn::fromString('lettermint+api://token@default'));
        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->subject('Test')->text('Hello'));
        self::assertSame(['before', 'after'], $events);
    }
}
