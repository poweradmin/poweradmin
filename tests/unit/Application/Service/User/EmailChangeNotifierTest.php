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
 */

namespace Poweradmin\Tests\Unit\Application\Service\User;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Mail\MailService;
use Poweradmin\Application\Service\User\EmailChangeNotifier;
use TestHelpers\FakeConfiguration;

class EmailChangeNotifierTest extends TestCase
{
    public function testSendsNoticeToOldAddressWhenMailIsEnabled(): void
    {
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->once())->method('sendMail')->with(
            'old@example.com',
            $this->stringContains('email address'),
            $this->stringContains('new@example.com'),
            $this->stringContains('new@example.com')
        )->willReturn(true);

        $notifier = new EmailChangeNotifier($mail, new FakeConfiguration(['mail' => ['enabled' => true]]));

        $this->assertTrue($notifier->notifyEmailChanged('old@example.com', 'new@example.com', 'Alice'));
    }

    public function testSendsNothingWhenMailIsDisabled(): void
    {
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('sendMail');

        $notifier = new EmailChangeNotifier($mail, new FakeConfiguration(['mail' => ['enabled' => false]]));

        // Nothing to send is not a failure, so the caller logs no warning
        $this->assertTrue($notifier->notifyEmailChanged('old@example.com', 'new@example.com', 'Alice'));
    }
}
