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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\OwnerGroupColumnPresenter;
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

/**
 * The zone list pages read every column ZoneSummary carries, both as the
 * decorated rows the list controllers build from toArray() and, on the delete
 * user page, straight through ArrayAccess. Under strict_variables a missing key
 * would abort the render, so this pins the read model against the templates.
 */
class ZoneListTemplateRenderTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/templates/default');
        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension(new Translator('en')));
        $this->twig->addExtension(new BadgeTwigExtension());
        $this->twig->addExtension(new IntlExtension());
        $this->twig->addGlobal('base_url_prefix', '');
        $this->twig->addGlobal('csrf_token', 'token');
        $this->twig->addGlobal('rows_per_page_options', [10, 20, 50, 100]);
    }

    /**
     * @return array<string, ZoneSummary>
     */
    private function zones(): array
    {
        return [
            'signed.example' => ZoneSummary::fromRow([
                'id' => 7,
                'name' => 'signed.example',
                'type' => 'MASTER',
                'count_records' => 3,
                'is_disabled' => false,
                'is_missing_soa' => false,
                'soa_health' => 'ok',
                'comment' => 'signed zone',
                'secured' => true,
                'owners' => ['alice', 'bob'],
                'full_names' => ['Alice A', ''],
                'serial' => '2024010101',
                'signed_serial' => '2024010105',
                'template' => 'Basic',
                'notified_serial' => 2024010100,
                'notify_pending' => true,
            ]),
            'plain.example' => ZoneSummary::fromRow([
                'id' => 9,
                'name' => 'plain.example',
                'type' => 'SLAVE',
                'count_records' => 1,
                'is_disabled' => true,
                'is_missing_soa' => false,
                'soa_health' => 'soa_disabled',
                'comment' => '',
                'secured' => false,
                'owners' => [],
                'full_names' => [],
                'serial' => '',
                'signed_serial' => '',
                'template' => '',
            ]),
        ];
    }

    /**
     * The rows as ListForwardZonesController hands them to the template.
     *
     * @return list<array<string, mixed>>
     */
    private function decoratedRows(): array
    {
        $rows = [];
        foreach ($this->zones() as $zone) {
            $row = $zone->toArray();
            $row['groups'] = $zone->id === 7 ? ['ops'] : [];
            $row['user_can_delete'] = true;
            $row['owners_display'] = OwnerGroupColumnPresenter::presentOwners($row['owners'], $row['full_names']);
            $row['groups_display'] = OwnerGroupColumnPresenter::presentGroups($row['groups']);
            $rows[] = $row;
        }

        return $rows;
    }

    public function testForwardListRendersEveryOptionalColumnFromTheReadModel(): void
    {
        $html = $this->twig->render('list_forward_zones.html', [
            'zones' => $this->decoratedRows(),
            'pending_change_requests_by_zone' => [7 => 1],
            'count_zones_all_letterstart' => 2,
            'count_zones_view' => 2,
            'count_zones_edit' => 2,
            'count_zones_delete' => 2,
            'letter_start' => 'all',
            'iface_rowamount' => 10,
            'zone_sort_by' => 'name',
            'zone_sort_direction' => 'ASC',
            'iface_zonelist_serial' => true,
            'iface_zonelist_signed_serial' => true,
            'iface_zonelist_template' => true,
            'iface_zonelist_record_count' => true,
            'is_record_count_sort_supported' => true,
            'iface_zonelist_fullname' => true,
            'show_owner_column' => true,
            'show_group_column' => true,
            'is_user_owner_allowed' => true,
            'is_group_owner_allowed' => true,
            'is_owner_sort_supported' => true,
            'is_group_sort_supported' => true,
            'pdnssec_use' => true,
            'letters' => '',
            'letters_items' => [],
            'pagination' => '',
            'pagination_items' => [],
            'session_userlogin' => 'alice',
            'perm_edit' => 'all',
            'perm_delete' => 'all',
            'can_bulk_delete_zones' => true,
            'perm_zone_master_add' => true,
            'perm_zone_slave_add' => true,
            'perm_is_godlike' => true,
            'is_api_backend' => true,
        ]);

        $this->assertStringContainsString('signed.example', $html);
        $this->assertStringContainsString('2024010101', $html);
        $this->assertStringContainsString('2024010105', $html);
        $this->assertStringContainsString('Basic', $html);
        $this->assertStringContainsString('NOTIFY pending', $html);
        $this->assertStringContainsString('Alice A', $html);
        $this->assertStringContainsString('plain.example', $html);
    }

    public function testDeleteUserPageReadsTheModelsThroughArrayAccess(): void
    {
        $html = $this->twig->render('delete_user.html', [
            'name' => 'alice',
            'uid' => 5,
            'zones' => $this->zones(),
            'zones_count' => 2,
            'users' => [['id' => 6, 'username' => 'bob', 'fullname' => '']],
            'lazy_owner_options' => false,
        ]);

        $this->assertStringContainsString('name="zone[7][zid]" value="7"', $html);
        $this->assertStringContainsString('<strong>signed.example</strong>', $html);
        $this->assertStringContainsString('name="zone[9][zid]" value="9"', $html);
    }
}
