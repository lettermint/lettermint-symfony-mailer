<?php

// Read the API source with synthetic data. No email sends or database writes.
$root = $argv[1] ?? throw new InvalidArgumentException('Pass the Laravel directory.');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mail = (new ReflectionClass(App\Data\MailerData::class))->newInstanceWithoutConstructor();
$mail->messageId = '00000000-0000-4000-8000-000000000001';
$reflection = new ReflectionClass(App\Http\Controllers\Api\V1\SendMailController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('responseData');
$mail->scheduledAt = null;
$fixtures['immediate'] = $method->invoke($controller, $mail);
$mail->scheduledAt = Carbon\CarbonImmutable::parse('2026-09-08T12:00:00Z');
$fixtures['scheduled'] = $method->invoke($controller, $mail);
$fixtures['request_fields'] = array_keys((new App\Http\Requests\Api\V1\SendMailRequest())->rules());
$fixtures['tls'] = array_column(App\Enums\TlsPolicy::cases(), 'value');
foreach ($app['router']->getRoutes() as $route) {
    if ($route->uri() === 'v1/send') {
        $fixtures['route'] = ['methods' => $route->methods(), 'uri' => $route->uri()];
    }
}
$payload = json_decode(file_get_contents(__DIR__.'/../tests/Fixtures/request.json'), true, flags: JSON_THROW_ON_ERROR);
$validator = $app['validator']->make($payload, app(App\Services\Messages\MessageRules::class)->rules());
if ($validator->fails()) {
    throw new RuntimeException($validator->errors()->toJson());
}
$fixtures['request_validated'] = true;
echo json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
