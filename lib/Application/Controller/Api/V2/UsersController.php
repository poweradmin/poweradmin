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
 * RESTful API controller for user operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Model\Pagination;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserManagementService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Exception;

#[OA\OpenApi(
    info: new OA\Info(
        version: '2.0.0',
        description: 'RESTful API for Poweradmin DNS Management (v2 - with wrapped responses)',
        title: 'Poweradmin API v2'
    ),
    servers: [
        new OA\Server(url: '/api', description: 'API Server')
    ]
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    bearerFormat: 'API Key',
    scheme: 'bearer'
)]
#[OA\SecurityScheme(
    securityScheme: 'apiKeyHeader',
    type: 'apiKey',
    name: 'X-API-Key',
    in: 'header'
)]

class UsersController extends PublicApiController
{
    private UserManagementService $userManagementService;
    private ApiPermissionService $apiPermissionService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $userRepository = new DbUserRepository($this->db, $this->config);
        $permissionService = new PermissionService($userRepository);
        $this->userManagementService = new UserManagementService($userRepository, $permissionService);
        $this->apiPermissionService = new ApiPermissionService($this->db);
    }

    /**
     * Handle user-related requests
     */
    #[\Override]
    public function run(): void
    {
        $method = $this->request->getMethod();

        $response = match ($method) {
            'GET' => isset($this->pathParameters['id']) ? $this->getUser() : $this->handleGetRequest(),
            'POST' => $this->createUser(),
            'PUT' => $this->updateUser(),
            'PATCH' => $this->assignPermissionTemplate(),
            'DELETE' => $this->deleteUser(),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * Get user details and permissions for a specific user ID
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/users/{id}',
        operationId: 'v2GetUser',
        description: 'Retrieves user information and permissions for a specific user ID',
        summary: 'Get user information by ID',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['users'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'User ID to retrieve information for',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'User information retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'User information retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(
                            property: 'user',
                            properties: [
                                new OA\Property(property: 'user_id', type: 'integer', example: 1),
                                new OA\Property(property: 'username', type: 'string', example: 'admin'),
                                new OA\Property(property: 'fullname', type: 'string', example: 'Administrator'),
                                new OA\Property(property: 'email', type: 'string', example: 'admin@example.com'),
                                new OA\Property(property: 'description', type: 'string', example: 'System Administrator'),
                                new OA\Property(property: 'active', type: 'boolean', example: true),
                                new OA\Property(property: 'is_admin', type: 'boolean', example: true),
                                new OA\Property(
                                    property: 'permissions',
                                    type: 'array',
                                    items: new OA\Items(type: 'string')
                                ),
                                new OA\Property(property: 'created_at', type: 'string', example: '2025-01-01 12:00:00'),
                                new OA\Property(property: 'updated_at', type: 'string', example: '2025-01-02 14:30:00')
                            ],
                            type: 'object'
                        )
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'meta',
                    properties: [
                        new OA\Property(property: 'timestamp', type: 'string', example: '2025-05-09 08:30:00')
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'User not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'User not found'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    private function getUser(): JsonResponse
    {
        try {
            $currentUserId = $this->getAuthenticatedUserId();
            $targetUserId = (int)$this->pathParameters['id'];

            // Check if user has permission to view this user
            if (!$this->apiPermissionService->canViewUser($currentUserId, $targetUserId)) {
                return $this->returnApiError('You do not have permission to view this user', 403);
            }

            // Use the domain service to get user details
            $userData = $this->userManagementService->getUserById($targetUserId);

            if (!$userData) {
                return $this->returnApiError('User not found', 404, null, [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]);
            }

            // Return with standard API format including meta block with timestamp
            return $this->returnApiResponse(
                ['user' => $userData],
                true,
                'User information retrieved successfully',
                200,
                [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'UsersController::getUser', 'Failed to retrieve user');
        }
    }

    /**
     * Handle GET requests - list users with optional filtering
     *
     * @return JsonResponse The JSON response
     */
    private function handleGetRequest(): JsonResponse
    {
        // listUsers now handles both listing and filtering
        return $this->listUsers();
    }


    /**
     * List all users with optional filtering
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Get(
        path: '/v2/users',
        operationId: 'v2ListUsers',
        description: 'Get a list of users. Can optionally filter by username or email.',
        summary: 'List all users with optional filtering',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['users']
    )]
    #[OA\Parameter(
        name: 'page',
        description: 'Page number for pagination (optional, only used when per_page is specified)',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Parameter(
        name: 'per_page',
        description: 'Number of users per page (optional, omit or set to 0 to return all users)',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 0)
    )]
    #[OA\Parameter(
        name: 'username',
        description: 'Filter by username (optional)',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'email',
        description: 'Filter by email (optional)',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'Users retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Users retrieved successfully'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'user_id', type: 'integer', example: 1),
                            new OA\Property(property: 'username', type: 'string', example: 'admin'),
                            new OA\Property(property: 'fullname', type: 'string', example: 'Administrator'),
                            new OA\Property(property: 'email', type: 'string', example: 'admin@example.com'),
                            new OA\Property(property: 'description', type: 'string', example: 'System Administrator'),
                            new OA\Property(property: 'active', type: 'boolean', example: true),
                            new OA\Property(property: 'zone_count', type: 'integer', example: 5),
                            new OA\Property(property: 'is_admin', type: 'boolean', example: true)
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(
                    property: 'pagination',
                    properties: [
                        new OA\Property(property: 'current_page', type: 'integer', example: 1),
                        new OA\Property(property: 'per_page', type: 'integer', example: 25),
                        new OA\Property(property: 'total', type: 'integer', example: 100),
                        new OA\Property(property: 'last_page', type: 'integer', example: 4)
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    private function listUsers(): JsonResponse
    {
        try {
            $currentUserId = $this->getAuthenticatedUserId();

            // Check if user has permission to list users
            if (!$this->apiPermissionService->canListUsers($currentUserId)) {
                return $this->returnApiError('You do not have permission to list users', 403);
            }

            // Get filter parameters
            $username = $this->request->query->get('username');
            $email = $this->request->query->get('email');

            // Handle filtering
            if ($username || $email) {
                $users = [];

                if ($username) {
                    $user = $this->userManagementService->getUserByUsername($username);
                    if ($user) {
                        $users[] = $user;
                    }
                } elseif ($email) {
                    $user = $this->userManagementService->getUserByEmail($email);
                    if ($user) {
                        $users[] = $user;
                    }
                }

                return $this->returnApiResponse($users, true, 'Users retrieved successfully', 200);
            }

            // Get pagination parameters (defaults to returning all users)
            $perPage = (int)$this->request->query->get('per_page', 0);

            // If per_page is 0 or not specified, return all users
            if ($perPage === 0) {
                // Get all users without pagination
                $pagination = new Pagination(0, PHP_INT_MAX, 1);
                $result = $this->userManagementService->getUsersList($pagination);
                $users = $result['data'];

                $responseData = [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ];

                return $this->returnApiResponse($users, true, 'Users retrieved successfully', 200, $responseData);
            } else {
                // Use pagination
                $page = max(1, (int)$this->request->query->get('page', 1));
                $perPage = min(10000, max(1, $perPage)); // Allow up to 10k per page

                // Create pagination object
                $pagination = new Pagination(0, $perPage, $page);

                // Use the domain service to get users list
                $result = $this->userManagementService->getUsersList($pagination);
                $users = $result['data'];
                $totalCount = $result['total_count'];

                $responseData = [
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $totalCount,
                        'last_page' => ceil($totalCount / $perPage)
                    ],
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ];

                return $this->returnApiResponse($users, true, 'Users retrieved successfully', 200, $responseData);
            }
        } catch (\Throwable $e) {
            return $this->handleException($e, 'UsersController::listUsers', 'Failed to retrieve users');
        }
    }

    /**
     * Create a new user
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Post(
        path: '/v2/users',
        operationId: 'v2CreateUser',
        description: 'Creates a new user in the system with the provided information',
        summary: 'Create a new user',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['users']
    )]
    #[OA\RequestBody(
        description: 'User information for creating a new user',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'username',
                    description: 'Unique username for the user',
                    type: 'string',
                    example: 'johndoe'
                ),
                new OA\Property(
                    property: 'password',
                    description: 'User password (will be hashed)',
                    type: 'string',
                    example: 'secure_password123'
                ),
                new OA\Property(
                    property: 'fullname',
                    description: 'Full name of the user',
                    type: 'string',
                    example: 'John Doe'
                ),
                new OA\Property(
                    property: 'email',
                    description: 'Email address of the user',
                    type: 'string',
                    example: 'johndoe@example.com'
                ),
                new OA\Property(
                    property: 'description',
                    description: 'Description or notes about the user',
                    type: 'string',
                    example: 'DNS zone manager'
                ),
                new OA\Property(
                    property: 'active',
                    description: 'Whether the user account is active',
                    type: 'boolean',
                    example: true
                ),
                new OA\Property(
                    property: 'perm_templ',
                    description: 'Permission template ID to assign to the user',
                    type: 'integer',
                    example: 1
                ),
                new OA\Property(
                    property: 'use_ldap',
                    description: 'Whether the user should use LDAP authentication',
                    type: 'boolean',
                    example: false
                )
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'User created successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'User created successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'user_id', type: 'integer', example: 123)
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'meta',
                    properties: [
                        new OA\Property(property: 'timestamp', type: 'string', example: '2025-05-09 08:30:00')
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation failed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Username is required'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 409,
        description: 'Conflict - username or email already exists',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Username already exists'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    private function createUser(): JsonResponse
    {
        try {
            $currentUserId = $this->getAuthenticatedUserId();

            // Check if user has permission to create users
            if (!$this->apiPermissionService->canCreateUser($currentUserId)) {
                return $this->returnApiError('You do not have permission to create users', 403);
            }

            $input = json_decode($this->request->getContent(), true);

            if (!$input) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Use the domain service to create user
            $result = $this->userManagementService->createUser($input);

            if (!$result['success']) {
                $statusCode = match ($result['message']) {
                    'Username already exists', 'Email already exists' => 409,
                    default => 400
                };

                return $this->returnApiError($result['message'], $statusCode, null, [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]);
            }

            return $this->returnApiResponse(
                ['user_id' => $result['user_id']],
                true,
                $result['message'],
                201,
                [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'UsersController::createUser', 'Failed to create user');
        }
    }

    /**
     * Update an existing user
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Put(
        path: '/v2/users/{id}',
        operationId: 'v2UpdateUser',
        description: 'Updates an existing user with the provided information. All fields are optional - only provided fields will be updated.',
        summary: 'Update an existing user',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['users']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'User ID to update',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        description: 'User information to update (all fields optional)',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'username',
                    description: 'New username for the user',
                    type: 'string',
                    example: 'johndoe_updated'
                ),
                new OA\Property(
                    property: 'password',
                    description: 'New password for the user (will be hashed)',
                    type: 'string',
                    example: 'new_secure_password123'
                ),
                new OA\Property(
                    property: 'fullname',
                    description: 'Updated full name of the user',
                    type: 'string',
                    example: 'John Updated Doe'
                ),
                new OA\Property(
                    property: 'email',
                    description: 'Updated email address of the user',
                    type: 'string',
                    example: 'john.updated@example.com'
                ),
                new OA\Property(
                    property: 'description',
                    description: 'Updated description or notes about the user',
                    type: 'string',
                    example: 'Senior DNS zone manager'
                ),
                new OA\Property(
                    property: 'active',
                    description: 'Whether the user account should be active',
                    type: 'boolean',
                    example: true
                ),
                new OA\Property(
                    property: 'perm_templ',
                    description: 'Permission template ID to assign to the user',
                    type: 'integer',
                    example: 2
                ),
                new OA\Property(
                    property: 'use_ldap',
                    description: 'Whether the user should use LDAP authentication',
                    type: 'boolean',
                    example: false
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'User updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'User updated successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'user_id', type: 'integer', example: 123)
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'meta',
                    properties: [
                        new OA\Property(property: 'timestamp', type: 'string', example: '2025-05-09 08:30:00')
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - validation failed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid JSON in request body'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'User not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'User not found'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    #[OA\Response(
        response: 409,
        description: 'Conflict - username or email already exists, or cannot disable last super admin',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Cannot disable the last remaining super admin user. At least one active super admin must exist in the system.'),
                new OA\Property(property: 'data', type: 'null')
            ]
        )
    )]
    private function updateUser(): JsonResponse
    {
        try {
            $currentUserId = $this->getAuthenticatedUserId();
            $targetUserId = (int)$this->pathParameters['id'];

            // Check if user has permission to edit this user
            if (!$this->apiPermissionService->canEditUser($currentUserId, $targetUserId)) {
                return $this->returnApiError('You do not have permission to edit this user', 403);
            }

            $input = json_decode($this->request->getContent(), true);

            if (!$input) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }

            // Use the domain service to update user
            $result = $this->userManagementService->updateUser($targetUserId, $input);

            if (!$result['success']) {
                $statusCode = match ($result['message']) {
                    'User not found' => 404,
                    'Username already exists', 'Email already exists' => 409,
                    'Cannot disable the last remaining super admin user. At least one active super admin must exist in the system.' => 409,
                    default => 400
                };

                return $this->returnApiError($result['message'], $statusCode, null, [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]);
            }

            return $this->returnApiResponse(
                ['user_id' => $result['user_id']],
                true,
                $result['message'],
                200,
                [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'UsersController::updateUser', 'Failed to update user');
        }
    }

    /**
     * Delete a user
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Delete(
        path: '/v2/users/{id}',
        operationId: 'v2DeleteUser',
        description: 'Delete a user from the system. Prevents deletion of the last remaining super admin to avoid system lockout. If user owns zones, you must specify transfer_to_user_id to transfer zones to another user.',
        summary: 'Delete a user',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['users']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'User ID to delete',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        description: 'Zone transfer options (required if user owns zones)',
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'transfer_to_user_id',
                    description: 'User ID to transfer zones to (required if user owns zones)',
                    type: 'integer',
                    example: 2
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'User deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'User deleted successfully. 3 zones transferred'),
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'zones_affected', type: 'integer', example: 3)
                ], type: 'object')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'User not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'User not found')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - last super admin cannot be deleted or missing transfer target',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'User owns zones. Please specify transfer_to_user_id to transfer zones to another user.')
            ]
        )
    )]
    private function deleteUser(): JsonResponse
    {
        try {
            $currentUserId = $this->getAuthenticatedUserId();
            $targetUserId = (int)$this->pathParameters['id'];

            // Check if user has permission to delete this user
            if (!$this->apiPermissionService->canDeleteUser($currentUserId, $targetUserId)) {
                return $this->returnApiError('You do not have permission to delete this user', 403);
            }

            // Get request body for zone transfer options
            $requestBody = json_decode($this->request->getContent(), true) ?? [];
            $transferToUserId = isset($requestBody['transfer_to_user_id']) ? (int)$requestBody['transfer_to_user_id'] : null;

            // Use the domain service to delete the user
            $result = $this->userManagementService->deleteUser($targetUserId, $transferToUserId);

            if (!$result['success']) {
                $statusCode = $result['message'] === 'User not found' ? 404 : 400;
                return $this->returnApiError($result['message'], $statusCode);
            }

            return $this->returnApiResponse(
                [
                    'zones_affected' => $result['zones_affected'] ?? 0
                ],
                true,
                $result['message'],
                200,
                [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'UsersController::deleteUser', 'Failed to delete user');
        }
    }

    /**
     * Assign permission template to user
     *
     * @return JsonResponse The JSON response
     */
    #[OA\Patch(
        path: '/v2/users/{id}',
        operationId: 'v2AssignPermissionTemplate',
        description: 'Assigns a permission template to a specific user',
        summary: 'Assign permission template to user',
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        tags: ['users']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'User ID',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        description: 'Permission template assignment data',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'perm_templ',
                    description: 'Permission template ID to assign to the user',
                    type: 'integer',
                    example: 2
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Permission template assigned successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Permission template assigned successfully'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'user_id', type: 'integer', example: 123),
                        new OA\Property(property: 'perm_templ', type: 'integer', example: 2)
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'User not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'User not found')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - invalid permission template ID',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'Invalid permission template ID')
            ]
        )
    )]
    private function assignPermissionTemplate(): JsonResponse
    {
        try {
            $currentUserId = $this->getAuthenticatedUserId();
            $targetUserId = (int)$this->pathParameters['id'];

            // Check if user has permission to edit permission templates
            if (!$this->apiPermissionService->canEditPermissionTemplates($currentUserId)) {
                return $this->returnApiError('You do not have permission to edit permission templates', 403);
            }

            $input = json_decode($this->request->getContent(), true);

            if (!$input || !isset($input['perm_templ'])) {
                return $this->returnApiError('Missing required field: perm_templ', 400);
            }

            $permTemplId = (int)$input['perm_templ'];

            // Use the domain service to assign permission template
            $result = $this->userManagementService->assignPermissionTemplate($targetUserId, $permTemplId);

            if (!$result['success']) {
                $statusCode = $result['message'] === 'User not found' ? 404 : 400;
                return $this->returnApiError($result['message'], $statusCode);
            }

            return $this->returnApiResponse(
                [
                    'user_id' => $targetUserId,
                    'perm_templ' => $permTemplId
                ],
                true,
                'Permission template assigned successfully',
                200,
                [
                    'meta' => [
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]
            );
        } catch (\Throwable $e) {
            return $this->returnApiError('Failed to assign permission template: ' . $e->getMessage(), 500);
        }
    }
}
