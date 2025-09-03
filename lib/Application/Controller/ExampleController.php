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

/**
 * POWERADMIN CONTROLLER SKELETON
 *
 * This is an example controller demonstrating the standard Poweradmin controller pattern
 * using the modern Symfony routing system.
 *
 * == HOW TO ENABLE THIS PAGE ==
 *
 * 1. Add a route definition to config/routes.yaml:
 *    ```yaml
 *    example:
 *      path: /example
 *      controller: Poweradmin\Application\Controller\ExampleController::run
 *      methods: [GET, POST]
 *    ```
 *
 * 2. Create a corresponding template file in both template themes:
 *    - templates/default/example.html
 *    - templates/modern/example.html
 *
 * 3. Access your page via: http://localhost/example
 *
 * == CONTROLLER REQUIREMENTS ==
 *
 * - Must extend BaseController
 * - Must implement run() method
 * - Must use proper namespace: Poweradmin\Application\Controller
 * - Class name must follow pattern: {Feature}Controller
 * - Should use dependency injection for services
 * - Should validate permissions before performing actions
 * - Should validate CSRF tokens for POST requests
 * - Should use proper error handling and user feedback
 * - Should use Symfony Validator for input validation
 *
 * == COMMON PATTERNS ==
 *
 * - Check permissions with: $this->checkPermission('permission_name', 'Error message')
 * - Validate CSRF for POST: $this->validateCsrfToken()
 * - Show errors with: $this->showError('Error message')
 * - Redirect with: $this->redirect('/target-url')
 * - Render template with: $this->render('template.html', $params)
 * - Get safe input with: $this->getSafeRequestValue('field_name')
 * - Set validation constraints with: $this->setValidationConstraints($constraints)
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Exception;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Service\MessageService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ExampleController demonstrates standard Poweradmin controller implementation
 *
 * This controller showcases common patterns including:
 * - Permission checking
 * - Form handling (GET/POST)
 * - CSRF token validation
 * - User input validation
 * - Service dependency injection
 * - Template rendering
 * - Error handling and user feedback
 */
class ExampleController extends BaseController
{
    private UserContextService $userContextService;
    protected MessageService $messageService;

    /**
     * Constructor - initialize required services
     *
     * @param array $request Request parameters from $_REQUEST
     */
    public function __construct(array $request)
    {
        // Call parent constructor with authentication enabled (true by default)
        parent::__construct($request, true);

        // Initialize required services
        $this->userContextService = new UserContextService();
        $this->messageService = new MessageService();
    }

    /**
     * Main controller entry point
     *
     * This method is called by the router and contains the main logic flow.
     * Common pattern: check permissions, then route to appropriate handler.
     */
    public function run(): void
    {
        // Example: Check if user has required permission
        // Replace 'user_view_others' with your specific permission
        $this->checkPermission('user_view_others', _('You do not have the permission to access this feature.'));

        // Route based on request method
        if ($this->isPost()) {
            $this->handleFormSubmission();
        } else {
            $this->showForm();
        }
    }

    /**
     * Display the form (GET request handler)
     *
     * This method handles displaying the initial form/page content.
     */
    private function showForm(): void
    {
        // Example: Get current user information
        $currentUser = $this->userContextService->getLoggedInUsername();
        $userId = $this->userContextService->getLoggedInUserId();

        // Example: Get some data to display
        $exampleData = $this->getExampleData();

        // Example: Check additional permissions for conditional display
        $canEdit = UserManager::verifyPermission($this->db, 'user_edit_others');
        $canDelete = UserManager::verifyPermission($this->db, 'user_delete_others');

        // Prepare template variables
        $templateVars = [
            'current_user' => $currentUser,
            'user_id' => $userId,
            'example_data' => $exampleData,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'config_value' => $this->config->get('example', 'setting', 'default_value'),
        ];

        // Render the template
        $this->render('example.html', $templateVars);
    }

    /**
     * Handle form submission (POST request handler)
     *
     * This method handles processing form submissions with proper validation.
     */
    private function handleFormSubmission(): void
    {
        // Validate CSRF token first
        $this->validateCsrfToken();

        // Get and validate form input
        $exampleField = $this->getSafeRequestValue('example_field');
        $numericField = $this->getSafeRequestValue('numeric_field');

        // Example: Set Symfony validation constraints
        $constraints = [
            'example_field' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => 3, 'max' => 100])
            ],
            'numeric_field' => [
                new Assert\NotBlank(),
                new Assert\Type('integer'),
                new Assert\Range(['min' => 1, 'max' => 1000])
            ]
        ];

        $this->setValidationConstraints($constraints);

        // Validate the request
        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
            return;
        }

        try {
            // Example: Process the form data
            $success = $this->processFormData($exampleField, $numericField);

            if ($success) {
                // Success: Set success message and redirect
                $this->setMessage('example', 'success', _('Operation completed successfully.'));
                $this->redirect('/example');
            } else {
                // Business logic failure
                $this->showError(_('Failed to process the request. Please try again.'));
            }
        } catch (Exception $e) {
            // Log the error for debugging
            error_log('ExampleController error: ' . $e->getMessage());

            // Show user-friendly error message
            $this->showError(_('An unexpected error occurred. Please contact the administrator.'));
        }
    }

    /**
     * Example method for retrieving data
     *
     * @return array Example data array
     */
    private function getExampleData(): array
    {
        // Example: Query database or call service
        // This is where you would typically:
        // - Call repository methods
        // - Use domain services
        // - Process business logic

        return [
            'item1' => ['id' => 1, 'name' => 'Example Item 1', 'status' => 'active'],
            'item2' => ['id' => 2, 'name' => 'Example Item 2', 'status' => 'inactive'],
        ];
    }

    /**
     * Example method for processing form data
     *
     * @param string $exampleField The example field value
     * @param string $numericField The numeric field value
     * @return bool Success status
     */
    private function processFormData(string $exampleField, string $numericField): bool
    {
        // Example: Process the submitted data
        // This is where you would typically:
        // - Validate business rules
        // - Call domain services
        // - Update database via repositories
        // - Send notifications
        // - Log user actions

        // Simulate processing
        if (empty($exampleField)) {
            return false;
        }

        // Example: Log user action (if database logging is enabled)
        if ($this->config->get('logging', 'database_enabled', false)) {
            // Log the action using appropriate logger
            // Example: UserEventLogger, ZoneEventLogger, etc.
        }

        return true;
    }
}
