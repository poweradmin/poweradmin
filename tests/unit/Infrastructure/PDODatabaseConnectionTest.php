<?php

namespace Poweradmin\Tests\Unit\Infrastructure;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use ReflectionClass;

#[CoversClass(PDODatabaseConnection::class)]
class PDODatabaseConnectionTest extends TestCase
{
    private PDODatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new PDODatabaseConnection();
    }

    #[Test]
    public function testBuildDriverOptionsDisablesSslVerificationForMysql(): void
    {
        $credentials = ['db_type' => 'mysql'];
        $options = $this->invokePrivateMethod('buildDriverOptions', [$credentials]);

        $this->assertArrayHasKey(PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $options);
        $this->assertFalse($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    #[Test]
    public function testBuildDriverOptionsDisablesSslVerificationForMysqli(): void
    {
        $credentials = ['db_type' => 'mysqli'];
        $options = $this->invokePrivateMethod('buildDriverOptions', [$credentials]);

        $this->assertArrayHasKey(PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $options);
        $this->assertFalse($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    #[Test]
    public function testBuildDriverOptionsReturnsEmptyArrayForPgsql(): void
    {
        $credentials = ['db_type' => 'pgsql'];
        $options = $this->invokePrivateMethod('buildDriverOptions', [$credentials]);

        $this->assertEmpty($options);
    }

    #[Test]
    public function testBuildDriverOptionsReturnsEmptyArrayForSqlite(): void
    {
        $credentials = ['db_type' => 'sqlite'];
        $options = $this->invokePrivateMethod('buildDriverOptions', [$credentials]);

        $this->assertEmpty($options);
    }

    #[Test]
    #[DataProvider('mysqlDatabaseTypesProvider')]
    public function testBuildDriverOptionsHandlesBothMysqlTypes(string $dbType): void
    {
        $credentials = ['db_type' => $dbType];
        $options = $this->invokePrivateMethod('buildDriverOptions', [$credentials]);

        $this->assertArrayHasKey(
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT,
            $options,
            "SSL verification option should be set for {$dbType}"
        );
        $this->assertFalse(
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT],
            "SSL verification should be disabled for {$dbType}"
        );
    }

    public static function mysqlDatabaseTypesProvider(): array
    {
        return [
            'mysql type' => ['mysql'],
            'mysqli type' => ['mysqli'],
        ];
    }

    #[Test]
    #[DataProvider('nonMysqlDatabaseTypesProvider')]
    public function testBuildDriverOptionsDoesNotSetSslOptionsForNonMysql(string $dbType): void
    {
        $credentials = ['db_type' => $dbType];
        $options = $this->invokePrivateMethod('buildDriverOptions', [$credentials]);

        $this->assertArrayNotHasKey(
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT,
            $options,
            "SSL verification option should not be set for {$dbType}"
        );
    }

    public static function nonMysqlDatabaseTypesProvider(): array
    {
        return [
            'pgsql type' => ['pgsql'],
            'sqlite type' => ['sqlite'],
        ];
    }

    #[Test]
    public function testGetDefaultPortReturnsMysqlPort(): void
    {
        $port = $this->invokePrivateMethod('getDefaultPort', ['mysql']);
        $this->assertEquals(3306, $port);
    }

    #[Test]
    public function testGetDefaultPortReturnsMysqliPort(): void
    {
        $port = $this->invokePrivateMethod('getDefaultPort', ['mysqli']);
        $this->assertEquals(3306, $port);
    }

    #[Test]
    public function testGetDefaultPortReturnsPostgresPort(): void
    {
        $port = $this->invokePrivateMethod('getDefaultPort', ['pgsql']);
        $this->assertEquals(5432, $port);
    }

    #[Test]
    public function testGetDefaultPortReturnsNullForSqlite(): void
    {
        $port = $this->invokePrivateMethod('getDefaultPort', ['sqlite']);
        $this->assertNull($port);
    }

    /**
     * Invoke a private method on the connection object.
     *
     * @param string $methodName
     * @param array $parameters
     * @return mixed
     */
    private function invokePrivateMethod(string $methodName, array $parameters): mixed
    {
        $reflection = new ReflectionClass($this->connection);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->connection, $parameters);
    }
}
