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

namespace Poweradmin\Tests\Unit\Application\Controller\System;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\System\IndexController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Characterizes the dashboard variables that decide which cards render for a
 * ueberuser, an API key holder and a limited user.
 */
#[CoversClass(IndexController::class)]
class IndexControllerCardsTest extends SeamControllerTestCase
{
    /** @var list<string> Permissions hasPermission() answers yes to */
    private array $granted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true));
        $permissions->method('getPermissionFlags')->willReturnCallback(function (int $userId, array $names): array {
            $flags = [];
            foreach ($names as $name) {
                $flags[$name] = in_array($name, $this->granted, true);
            }
            return $flags;
        });
        $this->factory->method('permissionService')->willReturn($permissions);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     * @return array<string, mixed>
     */
    private function runAndRender(array $config = []): array
    {
        $config['interface'] = ($config['interface'] ?? []) + ['show_dashboard_stats' => false];
        $controller = new IndexController([], true, $this->environment($this->configure($config)));
        $controller->run();

        $this->assertSame('index.html', $this->output->rendered[0][0]);

        return $this->renderedParams();
    }

    public function testUeberuserWithApiEnabledGetsTheRawFlags(): void
    {
        $this->granted = [Permission::PERM_USER_IS_UEBERUSER];

        $params = $this->runAndRender(['api' => ['enabled' => true]]);

        $this->assertTrue($params['permissions'][Permission::PERM_USER_IS_UEBERUSER]);
        $this->assertFalse($params['permissions'][Permission::PERM_API_MANAGE_KEYS]);
        $this->assertTrue($params['api_enabled']);
        $this->assertFalse($params['pdns_api_enabled']);
        $this->assertFalse($params['show_pdns_status']);
        $this->assertFalse($params['is_api_backend']);
        $this->assertTrue($params['show_group_access_templates']);
        $this->assertFalse($params['is_limited_user']);
        $this->assertTrue($params['has_tools']);
        $this->assertTrue($params['show_api_keys_card']);
        $this->assertTrue($params['show_groups_card']);
        $this->assertFalse($params['show_pdns_status_card']);
        $this->assertFalse($params['show_pdns_version_fallback']);
        $this->assertFalse($params['show_views_card']);
        $this->assertFalse($params['show_edit_profile_card']);
    }

    public function testPdnsStatusAndGroupsCardsFollowTheirSettings(): void
    {
        $this->granted = [Permission::PERM_USER_IS_UEBERUSER];

        $params = $this->runAndRender(['interface' => ['show_pdns_status' => true]]);
        $this->assertFalse($params['show_pdns_status_card'], 'pdns_api is not configured');
        $this->assertTrue($params['show_groups_card']);

        $params = $this->runAndRender(['permissions' => ['show_group_access_templates' => false]]);
        $this->assertFalse($params['show_groups_card']);
    }

    public function testApiKeyHolderWithoutApiGetsTheRawFlags(): void
    {
        $this->granted = [Permission::PERM_API_MANAGE_KEYS, Permission::PERM_SEARCH];

        $params = $this->runAndRender();

        $this->assertTrue($params['permissions'][Permission::PERM_API_MANAGE_KEYS]);
        $this->assertFalse($params['api_enabled']);
        $this->assertFalse($params['has_tools']);
        $this->assertFalse($params['show_api_keys_card']);
        $this->assertFalse($params['show_groups_card']);
        $this->assertFalse($params['show_pdns_version_fallback']);

        $this->assertTrue($this->runAndRender(['api' => ['enabled' => true]])['show_api_keys_card']);
    }

    public function testLimitedUserIsTheOneWhoMayOnlyEditTheirOwnAccount(): void
    {
        $this->granted = [Permission::PERM_USER_EDIT_OWN];

        $params = $this->runAndRender();

        $this->assertTrue($params['is_limited_user']);
        $this->assertTrue($params['show_edit_profile_card']);
        $this->assertTrue($params['permissions'][Permission::PERM_USER_EDIT_OWN]);
        $this->assertSame(self::USER_ID, $params['user_id']);

        $this->granted = [Permission::PERM_USER_EDIT_OWN, Permission::PERM_USER_VIEW_OTHERS];
        $params = $this->runAndRender();
        $this->assertFalse($params['is_limited_user']);
        $this->assertFalse($params['show_edit_profile_card']);
    }

    /**
     * Renders the dashboard with strict variables, so a card that reads a
     * variable the controller does not pass fails here too.
     *
     * @param array<string, mixed> $params
     */
    private function renderDashboard(array $params): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 5) . '/templates/default'), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new Translator('en')));
        $twig->addExtension(new BadgeTwigExtension());
        $twig->addGlobal('base_url_prefix', '');
        $twig->addGlobal('nav', array_fill_keys([
            'batch_ptr', 'bulk_registration', 'database_consistency', 'group_logs', 'permissions', 'record_changes',
            'search', 'templates', 'user_list', 'user_logs', 'zone_add_master', 'zone_add_slave', 'zone_list', 'zone_logs',
        ], false));

        return $twig->render('index.html', $params);
    }

    public function testTheStatusCardRendersInBothDashboardLayouts(): void
    {
        // The admin sections and the non-admin grid are separate markup; the card must be in each
        foreach ([[Permission::PERM_SERVER_STATUS_VIEW], [Permission::PERM_USER_IS_UEBERUSER, Permission::PERM_SERVER_STATUS_VIEW]] as $granted) {
            $this->granted = $granted;
            $this->output->rendered = [];
            $params = $this->runAndRender();

            // What the controller passes once pdns_api is configured and the page is enabled
            $params['show_pdns_status_card'] = true;
            $params['has_dns_management'] = true;
            $params['pdns_server_status'] = ['display' => 'PowerDNS', 'running' => true, 'version' => '5.0.0'];
            $this->assertStringContainsString('data-testid="pdns-status-link"', $this->renderDashboard($params), implode(',', $granted));

            $params['show_pdns_status_card'] = false;
            $this->assertStringNotContainsString('data-testid="pdns-status-link"', $this->renderDashboard($params), implode(',', $granted));
        }
    }
}
