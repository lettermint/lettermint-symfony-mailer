<?php

namespace Lettermint\SymfonyMailer\Tests;

use Composer\InstalledVersions;
use Lettermint\SymfonyMailer\Transport\UserAgent;
use PHPUnit\Framework\TestCase;

class UserAgentTest extends TestCase
{
    public function testValueUsesThePackagePrefixAndANonEmptyVersion(): void
    {
        self::assertMatchesRegularExpression('#^lettermint-symfony-mailer/\S+$#', UserAgent::value());
        self::assertNotSame('lettermint-symfony-mailer/0.1.0', UserAgent::value());
        self::assertSame(UserAgent::value(), UserAgent::value());
    }

    public function testUnregisteredPackageFallsBackToDev(): void
    {
        self::assertSame('dev', UserAgent::version('lettermint/not-a-registered-package'));
    }

    public function testRegisteredPackageUsesItsPrettyVersionWithoutLeadingV(): void
    {
        $expected = InstalledVersions::getPrettyVersion('symfony/mailer');
        self::assertNotNull($expected);
        self::assertSame(ltrim($expected, 'v'), UserAgent::version('symfony/mailer'));
        self::assertStringStartsNotWith('v', UserAgent::version('symfony/mailer'));
    }
}
