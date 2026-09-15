<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Logger\ClassContextLogger;
use Psr\Log\AbstractLogger;
use Stringable;

class ClassContextLoggerTest extends TestCase
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    private array $lines = [];

    public function testTagsEveryCallWithTheShortClassName(): void
    {
        $logger = ClassContextLogger::for($this->capturingLogger(), self::class);

        $logger->warning('Login failed for {user}', ['user' => 'jdoe']);
        $logger->info('No context');

        $this->assertSame(
            [
                ['warning', 'Login failed for {user}', ['user' => 'jdoe', 'classname' => 'ClassContextLoggerTest']],
                ['info', 'No context', ['classname' => 'ClassContextLoggerTest']],
            ],
            $this->lines
        );
    }

    public function testTheInnermostCallerKeepsItsOwnTagWhenLoggersNest(): void
    {
        $outer = ClassContextLogger::for($this->capturingLogger(), 'App\\SessionAuthenticator');
        $inner = ClassContextLogger::for($outer, 'App\\SqlAuthenticator');

        $inner->error('inner');
        $outer->error('outer');

        $this->assertSame('SqlAuthenticator', $this->lines[0][2]['classname']);
        $this->assertSame('SessionAuthenticator', $this->lines[1][2]['classname']);
    }

    private function capturingLogger(): AbstractLogger
    {
        $capture = function (string $level, string $message, array $context): void {
            $this->lines[] = [$level, $message, $context];
        };

        return new class ($capture) extends AbstractLogger {
            public function __construct(private readonly \Closure $capture)
            {
            }

            public function log($level, Stringable|string $message, array $context = []): void
            {
                ($this->capture)((string)$level, (string)$message, $context);
            }
        };
    }
}
