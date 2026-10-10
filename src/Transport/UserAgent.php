<?php

namespace Lettermint\SymfonyMailer\Transport;

use Composer\InstalledVersions;

/** @internal */
final class UserAgent
{
    private const PACKAGE = 'lettermint/symfony-mailer';
    private const FALLBACK_VERSION = 'dev';

    private static ?string $value = null;

    public static function value(): string
    {
        return self::$value ??= 'lettermint-symfony-mailer/'.self::version(self::PACKAGE);
    }

    public static function version(string $package): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return self::FALLBACK_VERSION;
        }

        try {
            $version = InstalledVersions::getPrettyVersion($package);
        } catch (\OutOfBoundsException) {
            return self::FALLBACK_VERSION;
        }

        if (null === $version) {
            return self::FALLBACK_VERSION;
        }

        if (str_starts_with($version, 'v')) {
            $version = substr($version, 1);
        }

        return '' === $version ? self::FALLBACK_VERSION : $version;
    }
}
