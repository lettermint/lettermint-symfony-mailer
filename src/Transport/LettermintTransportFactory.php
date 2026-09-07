<?php

namespace Lettermint\SymfonyMailer\Transport;

use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class LettermintTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, 'lettermint', $this->getSupportedSchemes());
        }
        if ('default' !== $dsn->getHost() || null !== $dsn->getPort() || null !== $dsn->getPassword()) {
            throw new InvalidArgumentException('Use lettermint+api://PROJECT_TOKEN@default.');
        }
        $options = [];
        if (null !== $route = $dsn->getOption('route')) {
            $options['route'] = $route;
        }
        foreach (['track_opens', 'track_clicks'] as $key) {
            if (null !== $value = $dsn->getOption($key)) {
                $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if (null === $boolean) {
                    throw new InvalidArgumentException('Tracking options must be true or false.');
                }
                $options['settings'][$key] = $boolean;
            }
        }
        if (null !== $tls = $dsn->getOption('tls')) {
            $options['settings']['tls'] = $tls;
        }
        $timeout = $dsn->getOption('timeout', 15);
        if (!is_numeric($timeout) || !is_finite((float) $timeout) || (float) $timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be a positive number of seconds.');
        }

        return new LettermintApiTransport($this->getUser($dsn), $this->client, $this->dispatcher, $this->logger, $options, (float) $timeout);
    }

    /** @return list<string> */
    protected function getSupportedSchemes(): array
    {
        return ['lettermint+api'];
    }
}
