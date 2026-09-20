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

namespace Poweradmin\Application\Controller\Api\V2;

use OpenApi\Attributes as OA;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\ZoneChangeRequestService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * /api/v2/change-requests: lists and shows filed change requests, applies a
 * reviewer's decision and lets a requester withdraw their own request.
 * Every endpoint answers 404 while approval.enabled is off.
 */
#[OA\Schema(
    schema: 'ChangeRequest',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'zone_id', type: 'integer', example: 3),
        new OA\Property(property: 'zone_name', type: 'string', example: 'example.com'),
        new OA\Property(property: 'kind', type: 'string', enum: ['records', 'zone_delete'], example: 'records'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected', 'cancelled', 'failed'], example: 'pending'),
        new OA\Property(
            property: 'requester',
            properties: [
                new OA\Property(property: 'id', type: 'integer', nullable: true, example: 7),
                new OA\Property(property: 'username', type: 'string', example: 'operator'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'request_comment', type: 'string', nullable: true, example: 'New web server'),
        new OA\Property(property: 'base_serial', type: 'string', nullable: true, example: '2026092001'),
        new OA\Property(
            property: 'actions',
            description: 'The stored actions: add {op, after}, edit {op, record_id, before, after}, delete {op, record_id, before}, zone_delete {op}',
            type: 'array',
            items: new OA\Items(type: 'object')
        ),
        new OA\Property(property: 'zone_comment', type: 'string', nullable: true),
        new OA\Property(
            property: 'reviewer',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
                new OA\Property(property: 'username', type: 'string', example: 'admin'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'review_comment', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', example: '2026-09-20 10:00:00'),
        new OA\Property(property: 'reviewed_at', type: 'string', nullable: true),
        new OA\Property(property: 'applied_at', type: 'string', nullable: true),
        new OA\Property(property: 'error', type: 'string', nullable: true, description: 'Why applying failed, for status failed'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'ChangeRequestEnvelope',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(
            property: 'data',
            properties: [new OA\Property(property: 'change_request', ref: '#/components/schemas/ChangeRequest')],
            type: 'object'
        ),
        new OA\Property(property: 'message', type: 'string', example: 'Change request approved and applied.'),
    ],
    type: 'object'
)]
class ChangeRequestsController extends PublicApiController
{
    private const STATUSES = [
        ZoneChangeRequest::STATUS_PENDING,
        ZoneChangeRequest::STATUS_APPROVED,
        ZoneChangeRequest::STATUS_REJECTED,
        ZoneChangeRequest::STATUS_CANCELLED,
        ZoneChangeRequest::STATUS_FAILED,
    ];

    private ZoneChangeRequestRepositoryInterface $requests;
    private ZoneChangeRequestService $changeRequests;
    private ApiPermissionService $apiPermissionService;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->requests = $this->createZoneChangeRequestRepository();
        $this->changeRequests = $this->createZoneChangeRequestService();
        $this->apiPermissionService = $this->createApiPermissionService();
    }

    public function run(): void
    {
        $method = $this->request->getMethod();
        $decision = $this->pathParameters['decision'] ?? null;

        $response = match (true) {
            $method === 'GET' && isset($this->pathParameters['id']) => $this->getChangeRequest(),
            $method === 'GET' => $this->listChangeRequests(),
            $method === 'POST' && $decision === 'approve' => $this->approveChangeRequest(),
            $method === 'POST' && $decision === 'reject' => $this->rejectChangeRequest(),
            $method === 'DELETE' && $decision === null => $this->cancelChangeRequest(),
            default => $this->methodNotAllowed($decision === null ? ['GET', 'DELETE'] : ['POST']),
        };

        $response->send();
        exit;
    }

    /**
     * A decision changes the request and, on approval, the zone: neither is a create.
     */
    protected function requiredApiKeyOperations(): array
    {
        if (strtoupper($this->request->getMethod()) === 'POST') {
            return [ApiKeyScope::OP_UPDATE];
        }

        return parent::requiredApiKeyOperations();
    }

    #[OA\Get(
        path: '/v2/change-requests',
        operationId: 'v2ListChangeRequests',
        summary: 'List change requests',
        description: 'Change requests for the zones the caller may review plus the requests the caller filed. Answers 404 while change approval is disabled.',
        tags: ['change-requests'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'pending (default), approved, rejected, cancelled, failed or all', schema: new OA\Schema(type: 'string', example: 'pending')),
            new OA\Parameter(name: 'zone_id', in: 'query', description: 'Only requests for this zone', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number, used when per_page is given', schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Requests per page; omit to return every match', schema: new OA\Schema(type: 'integer', example: 25)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Change requests',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'change_requests', type: 'array', items: new OA\Items(ref: '#/components/schemas/ChangeRequest')),
                            ],
                            type: 'object'
                        ),
                        new OA\Property(
                            property: 'pagination',
                            description: 'Present when per_page was given',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 25),
                                new OA\Property(property: 'total', type: 'integer', example: 3),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Unknown status'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Change approval is not enabled'),
        ]
    )]
    private function listChangeRequests(): JsonResponse
    {
        try {
            if (($disabled = $this->refuseWhenApprovalDisabled()) !== null) {
                return $disabled;
            }

            $status = (string)$this->request->query->get('status', ZoneChangeRequest::STATUS_PENDING);
            if ($status !== 'all' && !in_array($status, self::STATUSES, true)) {
                return $this->returnApiError('Invalid status filter', 400);
            }
            $zoneId = (int)$this->request->query->get('zone_id', 0);
            $perPage = (int)$this->request->query->get('per_page', 0);
            $page = $perPage > 0 ? max(1, (int)$this->request->query->get('page', 1)) : 1;
            $perPage = $perPage > 0 ? min(self::MAX_PAGE_SIZE, $perPage) : 0;

            $filters = $this->visibilityFilters($this->getAuthenticatedUserId(), $status === 'all' ? [] : ['status' => $status], $zoneId > 0 ? $zoneId : null);
            $total = $this->requests->count($filters);
            $requests = $perPage > 0
                ? $this->requests->list($filters, ($page - 1) * $perPage, $perPage)
                : $this->requests->list($filters, 0, self::MAX_PAGE_SIZE);

            $extra = [];
            if ($perPage > 0) {
                $extra['pagination'] = [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int)ceil($total / $perPage)),
                ];
            }

            return $this->returnApiResponse(
                ['change_requests' => array_map($this->serialize(...), $requests)],
                true,
                'Change requests retrieved successfully',
                200,
                $extra
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ChangeRequestsController::listChangeRequests', 'Failed to retrieve change requests');
        }
    }

    #[OA\Get(
        path: '/v2/change-requests/{id}',
        operationId: 'v2GetChangeRequest',
        summary: 'Get a change request',
        description: 'Visible to reviewers of the zone and to the requester. stale_actions lists the indexes of actions the zone has moved away from since filing.',
        tags: ['change-requests'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Change request ID', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The change request',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'change_request', ref: '#/components/schemas/ChangeRequest'),
                                new OA\Property(property: 'stale_actions', type: 'array', items: new OA\Items(type: 'integer'), example: [0]),
                                new OA\Property(property: 'base_serial_mismatch', type: 'boolean', example: false),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Change request not found or change approval is not enabled'),
        ]
    )]
    private function getChangeRequest(): JsonResponse
    {
        try {
            if (($disabled = $this->refuseWhenApprovalDisabled()) !== null) {
                return $disabled;
            }
            $request = $this->load();
            if ($request instanceof JsonResponse) {
                return $request;
            }

            $userId = $this->getAuthenticatedUserId();
            if (
                $request->requesterId !== $userId
                && !$this->apiPermissionService->canReviewChangeRequests($userId, $request->zoneId)
                && $this->apiPermissionService->getChangeApprovalMode($userId, $request->zoneId) === ChangeApprovalPolicy::MODE_NONE
            ) {
                return $this->returnApiError('You do not have permission to view this change request', 403);
            }

            return $this->returnApiResponse([
                'change_request' => $this->serialize($request),
                'stale_actions' => $request->canBeApplied() ? $this->changeRequests->staleActions($request) : [],
                'base_serial_mismatch' => $request->canBeApplied() && $this->changeRequests->baseSerialMismatch($request),
            ], true, 'Change request retrieved successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ChangeRequestsController::getChangeRequest', 'Failed to retrieve change request');
        }
    }

    #[OA\Post(
        path: '/v2/change-requests/{id}/approve',
        operationId: 'v2ApproveChangeRequest',
        summary: 'Approve and apply a change request',
        description: 'Applies the stored actions to the zone as the caller. Needs the change approve permission for the zone together with the edit permission. '
            . 'A refused or failed write leaves the request in the failed state and answers with the failure. '
            . 'Approving a failed request applies it again; actions that already landed are skipped.',
        tags: ['change-requests'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Change request ID', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [new OA\Property(property: 'comment', type: 'string', example: 'Looks good')])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Applied', content: new OA\JsonContent(ref: '#/components/schemas/ChangeRequestEnvelope')),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Change request not found or change approval is not enabled'),
            new OA\Response(response: 409, description: 'Already decided'),
        ]
    )]
    private function approveChangeRequest(): JsonResponse
    {
        return $this->decide('approve');
    }

    #[OA\Post(
        path: '/v2/change-requests/{id}/reject',
        operationId: 'v2RejectChangeRequest',
        summary: 'Reject a change request',
        description: 'Marks the request rejected without touching the zone. Needs the change approve permission for the zone together with the edit permission.',
        tags: ['change-requests'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Change request ID', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [new OA\Property(property: 'comment', type: 'string', example: 'Wrong address')])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(ref: '#/components/schemas/ChangeRequestEnvelope')),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Change request not found or change approval is not enabled'),
            new OA\Response(response: 409, description: 'Already decided'),
        ]
    )]
    private function rejectChangeRequest(): JsonResponse
    {
        return $this->decide('reject');
    }

    #[OA\Delete(
        path: '/v2/change-requests/{id}',
        operationId: 'v2CancelChangeRequest',
        summary: 'Cancel your own pending change request',
        tags: ['change-requests'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Change request ID', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(ref: '#/components/schemas/ChangeRequestEnvelope')),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Not the requester'),
            new OA\Response(response: 404, description: 'Change request not found or change approval is not enabled'),
            new OA\Response(response: 409, description: 'Already decided'),
        ]
    )]
    private function cancelChangeRequest(): JsonResponse
    {
        try {
            if (($disabled = $this->refuseWhenApprovalDisabled()) !== null) {
                return $disabled;
            }
            $request = $this->load();
            if ($request instanceof JsonResponse) {
                return $request;
            }

            $userId = $this->getAuthenticatedUserId();
            if ($request->requesterId !== $userId) {
                return $this->returnApiError('Only the requester can cancel this request', 403);
            }

            return $this->relay($this->changeRequests->cancel($request->id, $userId));
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ChangeRequestsController::cancelChangeRequest', 'Failed to cancel change request');
        }
    }

    /**
     * Approve or reject as the caller after the review permission check.
     */
    private function decide(string $decision): JsonResponse
    {
        try {
            if (($disabled = $this->refuseWhenApprovalDisabled()) !== null) {
                return $disabled;
            }
            $request = $this->load();
            if ($request instanceof JsonResponse) {
                return $request;
            }

            $userId = $this->getAuthenticatedUserId();
            if (!$this->apiPermissionService->canReviewChangeRequests($userId, $request->zoneId)) {
                return $this->returnApiError('You do not have permission to review change requests for this zone', 403);
            }
            // Approving performs the filed writes, so the key needs their operations too
            if ($decision === 'approve' && !$this->scopeCoversActions($request)) {
                return $this->returnApiError('Forbidden: this API key is not permitted to perform this operation', 403);
            }

            $comment = $this->inputString($this->getJsonInput() ?? [], 'comment');
            $reviewer = $this->getAuthenticatedUsername();
            $result = $decision === 'approve'
                ? $this->changeRequests->approve($request->id, $userId, $reviewer, $comment)
                : $this->changeRequests->reject($request->id, $userId, $reviewer, $comment);

            return $this->relay($result);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ChangeRequestsController::' . $decision, 'Failed to ' . $decision . ' change request');
        }
    }

    private function scopeCoversActions(ZoneChangeRequest $request): bool
    {
        $scope = $this->getApiKeyScope();
        if (!$scope->isZoneAllowed($request->zoneId)) {
            return false;
        }
        foreach ($request->actions as $action) {
            $operation = match ($action['op'] ?? null) {
                ZoneChangeRequest::OP_ADD => ApiKeyScope::OP_CREATE,
                ZoneChangeRequest::OP_DELETE, ZoneChangeRequest::OP_ZONE_DELETE => ApiKeyScope::OP_DELETE,
                default => ApiKeyScope::OP_UPDATE,
            };
            if (!$scope->isOperationTypeAllowed($operation)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The service outcome as the API answer, with the request's current state
     * when it still exists.
     */
    private function relay(ZoneChangeRequestResult $result): JsonResponse
    {
        $request = $result->requestId !== null ? $this->requests->find($result->requestId) : null;
        $data = $request === null ? null : ['change_request' => $this->serialize($request)];

        return $result->success
            ? $this->returnApiResponse($data, true, $result->message)
            : $this->returnApiError($result->message, $result->status, $data);
    }

    /**
     * The request named by the path, or the 400/404/403 that stops the handler.
     * The API key's zone restriction applies to the request's zone.
     */
    private function load(): ZoneChangeRequest|JsonResponse
    {
        $id = (int)($this->pathParameters['id'] ?? 0);
        if ($id <= 0) {
            return $this->returnApiError('Valid change request ID is required', 400);
        }
        $request = $this->requests->find($id);
        if ($request === null) {
            return $this->returnApiError('Change request not found', 404);
        }

        return $this->enforceApiKeyZoneScope($request->zoneId) ?? $request;
    }

    /**
     * Repository filters for what the caller may see: requests in the zones
     * they review or requests they filed, within the key's zone scope.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function visibilityFilters(int $userId, array $filters, ?int $zoneId): array
    {
        $scope = $this->getApiKeyScope();
        $within = $scope->hasZoneRestriction() ? $scope->getZoneIds() : null;
        if ($zoneId !== null) {
            $within = $within === null || in_array($zoneId, $within, true) ? [$zoneId] : [];
        }
        $filters['zoneIds'] = $within;

        $reviewable = $this->apiPermissionService->getReviewableZoneIds($userId);
        if ($reviewable !== null) {
            $filters['reviewableZoneIds'] = $reviewable;
            $filters['orRequesterId'] = $userId;
        }

        return $filters;
    }

    private function refuseWhenApprovalDisabled(): ?JsonResponse
    {
        if ($this->config->get('approval', 'enabled', false)) {
            return null;
        }

        return $this->returnApiError(self::CHANGE_APPROVAL_DISABLED, 404);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(ZoneChangeRequest $request): array
    {
        return [
            'id' => $request->id,
            'zone_id' => $request->zoneId,
            'zone_name' => $request->zoneName,
            'kind' => $request->kind,
            'status' => $request->status,
            'requester' => ['id' => $request->requesterId, 'username' => $request->requesterName],
            'request_comment' => $request->requestComment,
            'base_serial' => $request->baseSerial,
            'actions' => $request->actions,
            'zone_comment' => $request->zoneComment,
            'reviewer' => $request->reviewerId === null && $request->reviewerName === null
                ? null
                : ['id' => $request->reviewerId, 'username' => $request->reviewerName],
            'review_comment' => $request->reviewComment,
            'created_at' => $request->createdAt,
            'reviewed_at' => $request->reviewedAt,
            'applied_at' => $request->appliedAt,
            'error' => $request->error,
        ];
    }
}
