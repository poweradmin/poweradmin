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
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

/**
 * The search a record was opened from rides on the edit link, then on the edit
 * form as hidden fields and its Cancel link (#1656). Values are escaped and the
 * links point at /search, whatever the search text holds.
 */
class SearchReturnTemplateRenderTest extends TestCase
{
    private const SEARCH_RETURN = ['query' => 'www "x" <b>&', 'records' => 'true', 'records_page' => '2'];

    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/templates/default');
        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension(new Translator('en')));
        $this->twig->addExtension(new BadgeTwigExtension());
        $this->twig->addExtension(new IntlExtension());
        $this->twig->addGlobal('base_url_prefix', '/pa');
        $this->twig->addGlobal('csrf_token', 'token');
        $this->twig->addGlobal('rows_per_page_options', [10, 20, 50, 100]);
        $this->twig->addGlobal('record_types_with_priority', ['MX']);
        $this->twig->addGlobal('deprecated_record_types', []);
        $this->twig->addGlobal('file_version', '1');
    }

    /** @param array<string, string> $searchReturn */
    private function renderEditForm(array $searchReturn): string
    {
        return $this->twig->render('edit_record.html', [
            'record_id' => 34,
            'record' => ['id' => 34, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            'soa_fields' => null,
            'soa_durations' => [],
            'soa_defaults' => ['refresh' => 10800, 'retry' => 3600, 'expire' => 604800, 'minimum' => 3600],
            'recordTypes' => ['A', 'AAAA'],
            'deprecated_types' => [],
            'zone_name' => 'example.com',
            'idn_zone_name' => '',
            'zone_display_name' => 'example.com',
            'zone_type' => 'MASTER',
            'zid' => 12,
            'perm_edit' => 'all',
            'user_is_zone_owner' => true,
            'zone_is_editable' => true,
            'edit_mode' => 'direct',
            'require_change_comment' => false,
            'iface_record_comments' => false,
            'comment' => '',
            'is_reverse_zone' => false,
            'iface_add_reverse_record' => false,
            'display_hostname_only' => false,
            'api_escape_warning' => false,
            'api_escape_rrsets' => [],
            'search_return' => $searchReturn,
        ]);
    }

    public function testTheEditFormCarriesTheSearchAsEscapedHiddenFields(): void
    {
        $html = $this->renderEditForm(self::SEARCH_RETURN);

        $this->assertStringContainsString('<input type="hidden" name="search_return[query]" value="www &quot;x&quot; &lt;b&gt;&amp;">', $html);
        $this->assertStringContainsString('<input type="hidden" name="search_return[records]" value="true">', $html);
        $this->assertStringContainsString('<input type="hidden" name="search_return[records_page]" value="2">', $html);
        $this->assertStringContainsString('href="/pa/search?query=www%20%22x%22%20%3Cb%3E%26&amp;records=true&amp;records_page=2" class="btn btn-outline-secondary btn-sm"', $html);
    }

    public function testWithoutASearchTheEditFormCancelsToTheZone(): void
    {
        $html = $this->renderEditForm([]);

        $this->assertStringNotContainsString('search_return', $html);
        $this->assertStringContainsString('href="/pa/zones/12/edit" class="btn btn-outline-secondary btn-sm"', $html);
    }

    /** @param array<string, string> $searchReturn */
    private function renderSearch(array $searchReturn): string
    {
        return $this->twig->render('search.html', [
            'message' => false,
            'zone_sort_by' => 'name',
            'zone_sort_direction' => 'ASC',
            'is_record_count_sort_supported' => true,
            'record_sort_by' => 'name',
            'record_sort_direction' => 'ASC',
            'query' => 'www',
            'search_by_zones' => false,
            'search_by_records' => true,
            'search_by_comments' => false,
            'search_by_wildcard' => true,
            'search_by_reverse' => true,
            'type_filter' => '',
            'content_filter' => '',
            'has_zones' => false,
            'has_records' => true,
            'found_zones' => [],
            'found_records' => [[
                'id' => 34, 'domain_id' => 12, 'name' => 'www.example.com', 'display_name' => 'www.example.com', 'type' => 'A',
                'prio' => 0, 'content' => '192.0.2.1', 'ttl' => 3600, 'disabled' => 0, 'disabled_label' => 'No', 'user_can_edit' => true,
            ]],
            'total_zones' => 0,
            'total_records' => 1,
            'record_comments_truncated' => false,
            'zones_page' => 1,
            'records_page' => 2,
            'zones_pager' => [],
            'records_pager' => [],
            'zones_pagination_items' => [],
            'records_pagination_items' => [],
            'zone_rowamount' => 10,
            'record_rowamount' => 10,
            'iface_zone_comments' => false,
            'iface_record_comments' => false,
            'edit_permission' => 'all',
            'delete_permission' => 'all',
            'can_bulk_delete_zones' => true,
            'can_bulk_delete_records' => true,
            'user_id' => 1,
            'show_zone_owners' => false,
            'is_owner_sort_supported' => false,
            'whois_action_patterns' => [],
            'rdap_action_patterns' => [],
            'record_types' => ['A'],
            'search_return' => $searchReturn,
        ]);
    }

    public function testTheSearchResultEditLinkCarriesTheSearch(): void
    {
        $html = $this->renderSearch(self::SEARCH_RETURN);

        $this->assertStringContainsString(
            'href="/pa/zones/12/records/34/edit?search_return%5Bquery%5D=www%20%22x%22%20%3Cb%3E%26&amp;search_return%5Brecords%5D=true&amp;search_return%5Brecords_page%5D=2"',
            $html
        );
    }

    public function testWithoutASearchTheEditLinkIsPlain(): void
    {
        $this->assertStringContainsString('href="/pa/zones/12/records/34/edit"', $this->renderSearch([]));
    }
}
