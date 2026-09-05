<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use ReflectionMethod;

class DbZoneLoggerDetailsTest extends TestCase
{
    private function processDetails(string $event): string
    {
        $logger = new DbZoneLogger($this->createMock(PDOLayer::class));
        $method = new ReflectionMethod(DbZoneLogger::class, 'processDetails');
        $method->setAccessible(true);

        return $method->invoke($logger, $event);
    }

    public function testMarkupInAnEventIsEscaped(): void
    {
        $details = $this->processDetails('content:<img/src=x/onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $details);
        $this->assertStringContainsString('&lt;img/src=x/onerror=alert(1)&gt;', $details);
    }

    public function testSpacesStillBecomeLineBreaks(): void
    {
        $details = $this->processDetails('name:www type:A content:1.2.3.4');

        $this->assertSame('name: www<br>type: A<br>content: 1.2.3.4', $details);
    }

    public function testQuotesAreEscaped(): void
    {
        $details = $this->processDetails('content:"v=spf1 -all"');

        $this->assertSame('content: &quot;v=spf1<br>-all&quot;', $details);
    }
}
