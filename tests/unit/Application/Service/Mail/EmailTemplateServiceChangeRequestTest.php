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

namespace Poweradmin\Tests\Unit\Application\Service\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Mail\EmailTemplateService;
use TestHelpers\FakeConfiguration;

#[CoversClass(EmailTemplateService::class)]
class EmailTemplateServiceChangeRequestTest extends TestCase
{
    private const URL = 'https://dns.example.test/zones/requests/12';

    private EmailTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmailTemplateService(new FakeConfiguration());
    }

    public function testFiledEmailCarriesZoneRequesterKindCommentAndLink(): void
    {
        $mail = $this->service->renderChangeRequestFiledEmail(
            'example.org',
            12,
            'Olaf Owner',
            'Rita Requester',
            'zone_delete',
            'Customer left',
            '2026-09-20 10:00:00',
            self::URL
        );

        $this->assertSame('Change Request #12 Filed: example.org', $mail['subject']);
        foreach (['html', 'text'] as $part) {
            $this->assertStringContainsString('example.org', $mail[$part]);
            $this->assertStringContainsString('Rita Requester', $mail[$part]);
            $this->assertStringContainsString('Zone deletion', $mail[$part]);
            $this->assertStringContainsString('Customer left', $mail[$part]);
            $this->assertStringContainsString(self::URL, $mail[$part]);
        }
        $this->assertStringContainsString('Hi Olaf Owner,', $mail['text']);
    }

    public function testFiledEmailOmitsLinkAndCommentWhenAbsent(): void
    {
        $mail = $this->service->renderChangeRequestFiledEmail('example.org', 12, '', 'rita', 'records', '', '2026-09-20 10:00:00', null);

        $this->assertStringContainsString('Record changes', $mail['text']);
        $this->assertStringNotContainsString('Comment:', $mail['text']);
        $this->assertStringNotContainsString('visit:', $mail['text']);
        $this->assertStringNotContainsString('href=', $mail['html']);
        $this->assertStringContainsString('Hi,', $mail['text']);
    }

    public function testDecidedEmailWordsEachStatus(): void
    {
        $approved = $this->service->renderChangeRequestDecidedEmail('example.org', 12, 'Rita', 'approved', 'Olaf Owner', 'Looks good', '2026-09-20 11:00:00', self::URL);
        $rejected = $this->service->renderChangeRequestDecidedEmail('example.org', 12, 'Rita', 'rejected', 'Olaf Owner', '', '2026-09-20 11:00:00', self::URL);
        $failed = $this->service->renderChangeRequestDecidedEmail('example.org', 12, 'Rita', 'failed', '', '', '2026-09-20 11:00:00', null);

        $this->assertSame('Change Request #12 Approved: example.org', $approved['subject']);
        $this->assertSame('Change Request #12 Rejected: example.org', $rejected['subject']);
        $this->assertSame('Change Request #12 Failed: example.org', $failed['subject']);

        $this->assertStringContainsString('approved and applied', $approved['text']);
        $this->assertStringContainsString('Looks good', $approved['html']);
        $this->assertStringContainsString('Olaf Owner', $approved['html']);
        $this->assertStringContainsString(self::URL, $approved['html']);
        $this->assertStringContainsString(self::URL, $approved['text']);

        $this->assertStringContainsString('The zone was not changed', $rejected['text']);
        $this->assertStringNotContainsString('Review comment', $rejected['text']);

        $this->assertStringContainsString('applying it to the zone failed', $failed['text']);
        $this->assertStringNotContainsString('Reviewed by', $failed['text']);
        $this->assertStringNotContainsString('href=', $failed['html']);
        $this->assertStringContainsString('example.org', $failed['html']);
    }
}
