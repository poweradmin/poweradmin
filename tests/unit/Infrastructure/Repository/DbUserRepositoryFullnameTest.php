<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * A full name is stored without control or format characters.
 */
class DbUserRepositoryFullnameTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, fullname TEXT, email TEXT NOT NULL DEFAULT '')");
        $this->db->exec("INSERT INTO users VALUES (1, 'a', 'old', '', 'a@example.com')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn($group, $key) => $key === 'type' ? 'sqlite' : null);
        $this->repository = new DbUserRepository($this->db, $config);
    }

    public function testCleanFullnameStripsControlAndFormatCharactersOnly(): void
    {
        $this->assertSame('Jane Doe', UserManager::cleanFullname("Jane\r\n Doe"));
        $this->assertSame('AB', UserManager::cleanFullname("A\u{202E}\u{200B}B"));
        $this->assertSame("\u{0639}\u{0644}\u{06CC}\u{200C}\u{0631}\u{0636}\u{0627}", UserManager::cleanFullname("\u{0639}\u{0644}\u{06CC}\u{200C}\u{0631}\u{0636}\u{0627}"));
        $this->assertSame("\u{0DC1}\u{0DCA}\u{200D}\u{0DBB}\u{0DD3}", UserManager::cleanFullname("\u{0DC1}\u{0DCA}\u{200D}\u{0DBB}\u{0DD3}"));
        $this->assertSame('AB', UserManager::cleanFullname("A\u{FEFF}\u{2066}B"));
        $this->assertSame('AB', UserManager::cleanFullname("A\u{061C}B"));
        $this->assertSame('AB', UserManager::cleanFullname("A\u{E0041}\u{E007F}B"));
        $this->assertSame('Renée Müller-Łukasz 山田', UserManager::cleanFullname('Renée Müller-Łukasz 山田'));
    }

    public function testUpdateUserStoresCleanedFullname(): void
    {
        $this->assertTrue($this->repository->updateUser(1, ['fullname' => "Eve\r\nSubject: hi"]));

        $this->assertSame('EveSubject: hi', $this->db->query('SELECT fullname FROM users WHERE id = 1')->fetchColumn());
    }
}
