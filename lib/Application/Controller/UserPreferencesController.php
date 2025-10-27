<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Application\Controller;

use InvalidArgumentException;
use Exception;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserPreference;

class UserPreferencesController extends BaseController
{
    public function run(): void
    {
        // Check if user is logged in; if not, redirect to login page
        if (!$this->getUserContextService()->isAuthenticated()) {
            $this->redirect('/login');
            return;
        }

        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'user_preferences';

        // Users can always view/edit their own preferences
        $this->showUserPreferences();
    }

    private function showUserPreferences(): void
    {
        $userId = $this->getCurrentUserId();
        $userPreferenceService = $this->createUserPreferenceService();

        // Handle form submission
        if ($this->isPost()) {
            $this->handlePreferencesUpdate($userId, $userPreferenceService);
        }

        // Get current preferences
        $preferences = $userPreferenceService->getAllPreferences($userId);

        // Get available options
        $availableRowsPerPageOptions = $this->getAvailableRowsPerPageOptions();
        $availablePositions = $this->getAvailablePositions();

        // Prepare template variables
        $templateVars = [
            'preferences' => $preferences,
            'available_rows_per_page' => $availableRowsPerPageOptions,
            'available_positions' => $availablePositions,
        ];

        $this->render('user_preferences.html', $templateVars);
    }

    private function handlePreferencesUpdate(int $userId, $userPreferenceService): void
    {
        try {
            // Verify CSRF token
            $this->validateCsrfToken();

            // Update each preference that was submitted (excluding TTL as requested)
            $preferencesToUpdate = [
                UserPreference::KEY_ROWS_PER_PAGE => $_POST['rows_per_page'] ?? null,
                UserPreference::KEY_SHOW_ZONE_SERIAL => isset($_POST['show_zone_serial']) ? 'true' : 'false',
                UserPreference::KEY_SHOW_ZONE_TEMPLATE => isset($_POST['show_zone_template']) ? 'true' : 'false',
                UserPreference::KEY_RECORD_FORM_POSITION => $_POST['record_form_position'] ?? null,
                UserPreference::KEY_SAVE_BUTTON_POSITION => $_POST['save_button_position'] ?? null,
                UserPreference::KEY_SHOW_RECORD_ID => isset($_POST['show_record_id']) ? 'true' : 'false',
                UserPreference::KEY_SHOW_ADD_RECORD_FORM => isset($_POST['show_add_record_form']) ? 'true' : 'false',
                UserPreference::KEY_SHOW_RECORD_EDIT_BUTTON => isset($_POST['show_record_edit_button']) ? 'true' : 'false',
                UserPreference::KEY_SHOW_RECORD_DELETE_BUTTON => isset($_POST['show_record_delete_button']) ? 'true' : 'false',
            ];

            foreach ($preferencesToUpdate as $key => $value) {
                if ($value !== null) {
                    $userPreferenceService->setPreference($userId, $key, $value);
                }
            }

            $this->messageService->addMessage('user_preferences', 'success', _('Preferences saved successfully.'));

            // Redirect to prevent form resubmission and apply changes
            $this->redirect('/user/preferences');
            return;
        } catch (InvalidArgumentException $e) {
            $this->messageService->addMessage('user_preferences', 'error', $e->getMessage());
        } catch (Exception $e) {
            $this->messageService->addMessage('user_preferences', 'error', _('Failed to update preferences. Please try again.'));
        }
    }



    private function getAvailableRowsPerPageOptions(): array
    {
        return [10, 20, 50, 100];
    }


    private function getAvailablePositions(): array
    {
        return [
            'top' => _('Top'),
            'bottom' => _('Bottom'),
        ];
    }
}
