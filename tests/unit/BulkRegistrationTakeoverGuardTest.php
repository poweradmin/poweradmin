<?php

namespace unit;

use DomainException;
use PDOStatement;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Application\Controller\BulkRegistrationController;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Database\PDOLayer;
use ReflectionClass;
use ReflectionMethod;

/**
 * Bulk registration refuses a name that already carries records, per line, as the
 * single-zone form does.
 */
class BulkRegistrationTakeoverGuardTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testANameWithExistingRecordsIsNotCreated(): void
    {
        $_SESSION = ['userid' => 5, 'userlogin' => 'tester', 'messages' => []];
        $_POST = ['owner' => '5', 'dom_type' => 'MASTER', 'zone_template' => 'none', 'domains' => 'taken.example'];

        $db = $this->createMock(PDOLayer::class);
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchAll')->willReturn(['zone_content_edit_others' => []]);
        $db->method('prepare')->willReturn($statement);
        $db->method('quote')->willReturnCallback(static fn($value): string => "'" . $value . "'");
        $db->method('queryRow')->willReturn(false);
        $db->method('queryOne')->willReturn(1);
        // The zone must not be inserted
        $db->expects($this->never())->method('query');

        $controller = new class (new AppConfiguration()) extends BulkRegistrationController {
            public function __construct(private AppConfiguration $appConfig)
            {
            }

            public function getConfig(): AppConfiguration
            {
                return $this->appConfig;
            }

            public function setMessage(string $script, string $type, string $content): void
            {
                throw new DomainException($content);
            }
        };
        (new ReflectionClass(BaseController::class))->getProperty('db')->setValue($controller, $db);

        $this->expectExceptionObject(new DomainException('Some zone(s) could not be added.'));
        (new ReflectionMethod(BulkRegistrationController::class, 'doBulkRegistration'))->invoke($controller);
    }
}
