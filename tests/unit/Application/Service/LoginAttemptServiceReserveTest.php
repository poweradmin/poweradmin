<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * A guess is counted before it is checked, so parallel guesses cannot all read an empty counter;
 * a correct password clears the row it reserved.
 */
#[CoversClass(LoginAttemptService::class)]
class LoginAttemptServiceReserveTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $this->db->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, ip_address TEXT, timestamp INTEGER, successful INTEGER)');
        $this->db->exec("INSERT INTO users VALUES (1, 'victim')");
    }

    private function service(array $overrides = []): LoginAttemptService
    {
        $values = $overrides + [
            'account_lockout.enable_lockout' => true,
            'account_lockout.lockout_attempts' => 3,
            'account_lockout.lockout_duration' => 30,
            'account_lockout.track_ip_address' => true,
            'account_lockout.whitelist_ip_addresses' => [],
            'account_lockout.blacklist_ip_addresses' => [],
            'account_lockout.clear_attempts_on_success' => false,
            'type' => 'sqlite',
        ];
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $values[$key] ?? $default);

        return new LoginAttemptService($this->db, $config);
    }

    private function failures(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM login_attempts WHERE successful = 0')->fetchColumn();
    }

    public function testReservationsAreRefusedOnceTheLimitIsPassed(): void
    {
        $service = $this->service();

        $this->assertTrue($service->reserveAttempt('victim', '203.0.113.5'));
        $this->assertTrue($service->reserveAttempt('victim', '203.0.113.5'));
        $this->assertTrue($service->reserveAttempt('victim', '203.0.113.5'));
        $this->assertFalse($service->reserveAttempt('victim', '203.0.113.5'));
        $this->assertSame(4, $this->failures());
    }

    public function testAConfirmedAttemptRemovesOnlyItsReservedRowWhenClearOnSuccessIsOff(): void
    {
        $service = $this->service();
        $this->db->exec("INSERT INTO login_attempts (user_id, ip_address, timestamp, successful) VALUES (1, '203.0.113.5', " . time() . ", 0)");

        $this->assertTrue($service->reserveAttempt('victim', '203.0.113.5'));
        $this->assertSame(2, $this->failures());
        $service->confirmAttempt('victim', '203.0.113.5');

        $this->assertSame(1, $this->failures());
    }

    public function testAConfirmedAttemptClearsEarlierFailuresWhenClearOnSuccessIsOn(): void
    {
        $service = $this->service(['account_lockout.clear_attempts_on_success' => true]);
        $this->db->exec("INSERT INTO login_attempts (user_id, ip_address, timestamp, successful) VALUES (1, '203.0.113.5', " . time() . ", 0)");

        $this->assertTrue($service->reserveAttempt('victim', '203.0.113.5'));
        $service->confirmAttempt('victim', '203.0.113.5');

        $this->assertSame(0, $this->failures());
    }

    public function testRepeatedLegitimateChangesNeverLockTheAccount(): void
    {
        $service = $this->service();

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($service->reserveAttempt('victim', '203.0.113.5'));
            $service->confirmAttempt('victim', '203.0.113.5');
        }

        $this->assertFalse($service->isAccountLocked('victim', '203.0.113.5'));
    }

    public function testAWhitelistedAddressIsCountedButNeverRefused(): void
    {
        $service = $this->service(['account_lockout.whitelist_ip_addresses' => ['203.0.113.5']]);

        for ($i = 0; $i < 6; $i++) {
            $this->assertTrue($service->reserveAttempt('victim', '203.0.113.5'));
        }
        $this->assertSame(6, $this->failures());
    }

    public function testNothingIsCountedWhenLockoutIsOffOrTheUserIsUnknown(): void
    {
        $this->assertTrue($this->service(['account_lockout.enable_lockout' => false])->reserveAttempt('victim', '203.0.113.5'));
        $this->assertTrue($this->service()->reserveAttempt('nobody', '203.0.113.5'));

        $this->assertSame(0, $this->failures());
    }
}
