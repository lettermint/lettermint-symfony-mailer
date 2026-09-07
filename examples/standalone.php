<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Lettermint\SymfonyMailer\Transport\LettermintTransportFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

$live = in_array('--send', $argv, true);
$token = $live ? getenv('LETTERMINT_PROJECT_TOKEN') : 'local-test-token';
$from = $live ? getenv('LETTERMINT_FROM') : 'sender@example.com';
$to = $live ? getenv('LETTERMINT_TO') : 'recipient@example.com';
if (!$token || !$from || !$to) {
    throw new RuntimeException('Set LETTERMINT_PROJECT_TOKEN, LETTERMINT_FROM, and LETTERMINT_TO.');
}
$client = $live ? null : new MockHttpClient(new MockResponse('{"message_id":"local-test","status":"pending"}', ['http_code' => 202]));
$factory = new LettermintTransportFactory(client: $client);
$transport = (new Transport([$factory]))->fromString('lettermint+api://'.rawurlencode($token).'@default');
$email = (new Email())->from($from)->to($to)->subject('Symfony Mailer test')->text('Hello from the Lettermint Symfony Mailer bridge.');
if ($live) {
    $key = getenv('LETTERMINT_IDEMPOTENCY_KEY');
    if (!$key) {
        throw new RuntimeException('Set LETTERMINT_IDEMPOTENCY_KEY for the test send.');
    }
    $email->getHeaders()->addTextHeader('Idempotency-Key', $key);
}
$sent = $transport->send($email);
echo ($live ? 'API' : 'Local mock').' message ID: '.$sent->getMessageId().PHP_EOL;
