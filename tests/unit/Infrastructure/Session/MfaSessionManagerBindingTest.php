<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Infrastructure\Session;

use DateTime;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Enum\MfaSessionState;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Infrastructure\Session\MfaSessionManager;
use TestHelpers\FakeConfiguration;

/**
 * A session verified under one MFA enrolment is challenged again once the user enrols afresh
 * (disable, then set up again), so a factor rotation also ends the sessions an attacker holds.
 */
#[CoversClass(MfaSessionManager::class)]
#[CoversClass(UserMfa::class)]
#[CoversClass(DbUserMfaRepository::class)]
class MfaSessionManagerBindingTest extends TestCase
{
    private const USER_ID = 7;

    private ArraySession $session;
    private MfaSessionManager $manager;

    protected function setUp(): void
    {
        $this->session = new ArraySession([SessionKeys::USERID => self::USER_ID]);
        $this->manager = new MfaSessionManager($this->session);
    }

    public function testASessionVerifiedUnderTheCurrentEnrolmentStaysVerified(): void
    {
        $this->manager->setMfaVerified('enrolment-a');

        $this->manager->setMfaRequired(self::USER_ID, 'enrolment-a');

        $this->assertSame(MfaSessionState::VERIFIED, $this->manager->currentState());
    }

    public function testAnEnrolmentMadeAfterTheVerificationChallengesTheSessionAgain(): void
    {
        $this->manager->setMfaVerified('enrolment-a');

        $this->manager->setMfaRequired(self::USER_ID, 'enrolment-b');

        $this->assertSame(MfaSessionState::PENDING, $this->manager->currentState());
        $this->assertNull($this->session->get(AuthFlowSessionKeys::MFA_BINDING));
    }

    public function testASessionVerifiedBeforeBindingsExistedAdoptsTheCurrentOne(): void
    {
        $this->manager->setMfaVerified();

        $this->manager->setMfaRequired(self::USER_ID, 'enrolment-a');
        $this->assertSame(MfaSessionState::VERIFIED, $this->manager->currentState());

        $this->manager->setMfaRequired(self::USER_ID, 'enrolment-b');
        $this->assertSame(MfaSessionState::PENDING, $this->manager->currentState());
    }

    public function testWithoutABindingTheVerifiedSessionIsLeftAlone(): void
    {
        $this->manager->setMfaVerified('enrolment-a');

        $this->manager->setMfaRequired(self::USER_ID);

        $this->assertSame(MfaSessionState::VERIFIED, $this->manager->currentState());
    }

    public function testEnablingAgainChangesTheBindingAndSurvivesTheDatabase(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE user_mfa (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 0,
            secret VARCHAR(255) NULL, recovery_codes TEXT NULL, type VARCHAR(20) NOT NULL DEFAULT \'app\', last_used_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL, verification_data TEXT NULL)');
        $repository = new DbUserMfaRepository($db, new FakeConfiguration(['database' => ['type' => 'sqlite']]));

        $mfa = $repository->save(new UserMfa(0, self::USER_ID, false, 'S', null, UserMfa::TYPE_APP, null, new DateTime('2020-01-01 00:00:00'), null));
        $mfa->enable();
        $enabled = $repository->save($mfa)->sessionBinding();
        $this->assertSame($enabled, $repository->findByUserId(self::USER_ID)?->sessionBinding());
        $this->assertNotSame('2020-01-01 00:00:00', $db->query('SELECT created_at FROM user_mfa')->fetchColumn());

        // Make the first enrolment old, as it is by the time anyone enrols again
        $db->exec("UPDATE user_mfa SET created_at = '2020-01-01 00:00:00'");
        $first = $repository->findByUserId(self::USER_ID)?->sessionBinding();

        $mfa = $repository->findByUserId(self::USER_ID);
        $this->assertNotNull($mfa);
        $mfa->disable();
        $repository->save($mfa);
        $mfa = $repository->findByUserId(self::USER_ID);
        $this->assertNotNull($mfa);
        $mfa->enable();
        $second = $repository->save($mfa)->sessionBinding();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $repository->findByUserId(self::USER_ID)?->sessionBinding());
    }
}
