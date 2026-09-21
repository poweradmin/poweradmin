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
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLinkedCommentRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Service\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\ZoneChangeRequestService;
use Poweradmin\Domain\Service\ZoneEditSubmission;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\Database\DbCompat;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * /api/v2/zones/{id}/change-requests: files change requests for a zone
 * without writing to it. Answers 404 while approval.enabled is off.
 */
class ZonesChangeRequestsController extends PublicApiController
{
    private const ONE_ACTION_ONLY = 'Only one action per change request is supported';

    private ZoneReadRepositoryInterface $zoneRepository;
    private RecordLookupInterface $recordRepository;
    private RecordCommentRepositoryInterface $recordComments;
    private ?RecordLinkedCommentRepositoryInterface $linkedComments;
    private ZoneChangeRequestRepositoryInterface $requests;
    private ZoneChangeRequestService $changeRequests;
    private ApiPermissionService $apiPermissionService;
    private ReverseTtlResolver $reverseTtlResolver;

    public function __construct(array $request, array $pathParameters = [])
    {
        parent::__construct($request, $pathParameters);

        $this->zoneRepository = $this->services()->zoneRepository();
        $this->recordRepository = $this->services()->recordRepository();
        $this->recordComments = $this->services()->repositoryFactory()->createRecordCommentRepository();
        $this->linkedComments = $this->services()->repositoryFactory()->createRecordLinkedCommentRepository();
        $this->requests = $this->services()->zoneChangeRequestRepository();
        $this->changeRequests = $this->services()->zoneChangeRequestService();
        $this->apiPermissionService = $this->services()->apiPermissionService();
        $this->reverseTtlResolver = $this->services()->reverseTtlResolver();
    }

    public function run(): void
    {
        $response = match ($this->request->getMethod()) {
            'POST' => $this->fileChangeRequest(),
            default => $this->methodNotAllowed(['POST']),
        };

        $response->send();
        exit;
    }

    #[OA\Post(
        path: '/v2/zones/{id}/change-requests',
        operationId: 'v2CreateZoneChangeRequest',
        summary: 'File a change request for a zone',
        description: 'Stores the requested actions for review without writing to the zone. '
            . 'Allowed when the caller may edit the zone or holds a change request permission that covers it; '
            . 'SOA, NS and LUA records follow the same record-type rule as direct edits. '
            . 'A zone_delete action must be the only action and needs a request permission for the zone, or the delete permission when every change is reviewed. '
            . 'Each add and delete action is filed as its own request; all edit actions are filed together as one request. '
            . 'Actions are checked before anything is filed; when filing one of them is still refused, the response carries the requests filed so far under data.change_requests.',
        tags: ['change-requests'],
        security: [['bearerAuth' => []], ['apiKeyHeader' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Zone ID', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['actions'],
                properties: [
                    new OA\Property(property: 'comment', type: 'string', example: 'New web server'),
                    new OA\Property(
                        property: 'actions',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'op', type: 'string', enum: ['add', 'edit', 'delete', 'zone_delete'], example: 'add'),
                                new OA\Property(property: 'record_id', description: 'For edit and delete', oneOf: [new OA\Schema(type: 'integer'), new OA\Schema(type: 'string')], example: 123),
                                new OA\Property(
                                    property: 'record',
                                    description: 'For add (name, type and content required) and edit (omitted fields keep their stored value)',
                                    properties: [
                                        new OA\Property(property: 'name', type: 'string', example: 'www'),
                                        new OA\Property(property: 'type', type: 'string', example: 'A'),
                                        new OA\Property(property: 'content', type: 'string', example: '192.0.2.1'),
                                        new OA\Property(property: 'ttl', type: 'integer', example: 3600),
                                        new OA\Property(property: 'priority', type: 'integer', example: 0),
                                        new OA\Property(property: 'disabled', type: 'boolean', example: false),
                                        new OA\Property(property: 'comment', type: 'string', example: 'web server'),
                                    ],
                                    type: 'object'
                                ),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Filed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Change request filed for review.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'change_request', ref: '#/components/schemas/ChangeRequest', description: 'The first request filed'),
                                new OA\Property(property: 'change_requests', type: 'array', items: new OA\Items(ref: '#/components/schemas/ChangeRequest'), description: 'Every request filed by this call'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid body, unsupported action mix, or a row the validator refused'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Zone or record not found, or change approval is not enabled'),
            new OA\Response(response: 409, description: 'A record with this hostname, type, and content already exists'),
            new OA\Response(response: 413, description: 'The request is too large to store'),
        ]
    )]
    private function fileChangeRequest(): JsonResponse
    {
        try {
            if (!$this->config->get('approval', 'enabled', false)) {
                return $this->returnApiError(self::CHANGE_APPROVAL_DISABLED, 404);
            }

            $userId = $this->getAuthenticatedUserId();
            $zoneId = (int)($this->pathParameters['id'] ?? 0);
            if ($zoneId <= 0) {
                return $this->returnApiError('Valid zone ID is required', 400);
            }
            if (($scopeError = $this->enforceApiKeyZoneScope($zoneId)) !== null) {
                return $scopeError;
            }
            $zone = $this->zoneRepository->getZoneById($zoneId);
            if (!$zone) {
                return $this->returnApiError('Zone not found', 404);
            }
            $zoneName = (string)$zone['name'];
            $zoneType = $zone['type'] ?? null;

            $input = $this->getValidatedJsonBody();
            if ($input === null) {
                return $this->returnApiError('Invalid JSON in request body', 400);
            }
            $actions = $input['actions'] ?? null;
            if (!is_array($actions) || $actions === []) {
                return $this->returnApiError("Field 'actions' is required and must be a non-empty array", 400);
            }
            $comment = $this->inputString($input, 'comment');

            $ops = array_map(static fn($action): ?string => is_array($action) && is_string($action['op'] ?? null) ? $action['op'] : null, array_values($actions));
            foreach ($ops as $index => $op) {
                if (!in_array($op, [ZoneChangeRequest::OP_ADD, ZoneChangeRequest::OP_EDIT, ZoneChangeRequest::OP_DELETE, ZoneChangeRequest::OP_ZONE_DELETE], true)) {
                    return $this->returnApiError(sprintf('Action %d has an unknown op', $index + 1), 400);
                }
            }

            $username = $this->getAuthenticatedUsername();
            if (in_array(ZoneChangeRequest::OP_ZONE_DELETE, $ops, true)) {
                if (count($ops) > 1) {
                    return $this->returnApiError(self::ONE_ACTION_ONLY, 400);
                }
                if (!$this->apiPermissionService->canRequestZoneDelete($userId, $zoneId)) {
                    return $this->returnApiError('You do not have permission to request deletion of this zone', 403);
                }

                return $this->filed([$this->changeRequests->fileZoneDelete($zoneId, $userId, $username, $comment)], []);
            }

            if (ZoneType::isReadOnly($zoneType)) {
                return $this->returnApiError($this->zoneEditDeniedMessage($zoneType), 403);
            }
            if ($this->apiPermissionService->getChangeApprovalMode($userId, $zoneId) === ChangeApprovalPolicy::MODE_NONE) {
                return $this->returnApiError('You do not have permission to request changes to this zone', 403);
            }

            // Every action is checked before the first one is filed
            $planned = [];
            foreach (array_values($actions) as $index => $action) {
                $step = $this->plan($userId, $zoneId, $zoneName, $index, $action);
                if ($step instanceof JsonResponse) {
                    return $step;
                }
                $planned[] = $step;
            }

            $results = [];
            $editRows = [];
            foreach ($planned as $step) {
                if ($step['op'] === ZoneChangeRequest::OP_EDIT) {
                    $editRows[] = $step['row'];
                    continue;
                }
                $result = $step['op'] === ZoneChangeRequest::OP_ADD
                    ? $this->changeRequests->fileRecordAdd($zoneId, $zoneName, $step['record'], $userId, $username, $comment)
                    : $this->changeRequests->fileRecordDelete($zoneId, $step['record_id'], $userId, $username, $comment);
                if (!$result->success) {
                    return $this->filed($results, [$result]);
                }
                $results[] = $result;
            }
            if ($editRows !== []) {
                $submission = new ZoneEditSubmission($zoneId, $zoneName, $userId, $username, $editRows, true, null, false, null);
                $result = $this->changeRequests->fileRecordEdits($submission, $comment);
                if (!$result->success) {
                    return $this->filed($results, [$result]);
                }
                $results[] = $result;
            }

            return $this->filed($results, []);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'ZonesChangeRequestsController::fileChangeRequest', 'Failed to file change request');
        }
    }

    /**
     * Normalises one posted action and runs its permission and existence checks.
     *
     * @param mixed $action
     * @return array<string, mixed>|JsonResponse The planned step, or the refusal
     */
    private function plan(int $userId, int $zoneId, string $zoneName, int $index, mixed $action): array|JsonResponse
    {
        $op = $action['op'];
        $label = sprintf('Action %d', $index + 1);

        if ($op === ZoneChangeRequest::OP_ADD) {
            $record = is_array($action['record'] ?? null) ? $action['record'] : [];
            foreach (['name', 'type', 'content'] as $field) {
                $value = $this->inputString($record, $field);
                if ($value === null || trim($value) === '') {
                    return $this->returnApiError(sprintf("%s: field '%s' is required", $label, $field), 400);
                }
            }
            $type = strtoupper(trim((string)$record['type']));
            $name = $this->normalizeV2RecordName((string)$record['name'], $zoneName);
            $name = strtolower((new HostnameValidator($this->getConfig()))->normalizeRecordName($name, $zoneName));
            $ttl = $this->inputInt($record, 'ttl', $this->reverseTtlResolver->resolveTtlForType($type, DnsHelper::isReverseZoneName($zoneName)));
            $priority = $this->inputInt($record, 'priority', 0);
            $disabled = $this->inputIntFromBool($record, 'disabled', 0);
            if ($ttl === null || $priority === null || $disabled === null) {
                return $this->returnApiError($label . ': fields ttl, priority, and disabled must be numeric', 400);
            }
            // 0 is RFC-valid ("do not cache") and TTLValidator accepts it
            if ($ttl < 0) {
                return $this->returnApiError($label . ': TTL must not be negative', 400);
            }
            if (!$this->apiPermissionService->canRequestZoneRecord($userId, $zoneId, $type, $name, $zoneName)) {
                return $this->returnApiError('You do not have permission to edit this record type', 403);
            }

            return ['op' => $op, 'record' => [
                'name' => $name,
                'type' => $type,
                'content' => $this->formatV2RecordContent($type, trim((string)$record['content'])),
                'ttl' => $ttl,
                'prio' => $priority,
                'disabled' => $disabled,
                'comment' => trim((string)$this->inputString($record, 'comment', '')),
            ]];
        }

        $recordId = $action['record_id'] ?? null;
        if (!is_int($recordId) && !(is_string($recordId) && $recordId !== '')) {
            return $this->returnApiError($label . ': record_id is required', 400);
        }
        $recordId = RecordIdHelper::normalizeId($recordId);
        $existing = $this->recordRepository->getRecordById($recordId);
        if (!$existing || (int)$existing['domain_id'] !== $zoneId) {
            return $this->returnApiError('Record not found in this zone', 404);
        }
        if (!$this->apiPermissionService->canRequestZoneRecord($userId, $zoneId, (string)$existing['type'], (string)$existing['name'], $zoneName)) {
            return $this->returnApiError('You do not have permission to edit this record type', 403);
        }
        if ($op === ZoneChangeRequest::OP_DELETE) {
            return ['op' => $op, 'record_id' => $recordId];
        }

        $record = is_array($action['record'] ?? null) ? $action['record'] : [];
        $type = strtoupper(trim((string)($this->inputString($record, 'type', (string)$existing['type']) ?? '')));
        $name = $this->inputString($record, 'name', (string)$existing['name']);
        $content = $this->inputString($record, 'content', (string)$existing['content']);
        $ttl = $this->inputInt($record, 'ttl', (int)$existing['ttl']);
        $priority = $this->inputInt($record, 'priority', (int)($existing['prio'] ?? 0));
        $disabled = $this->inputIntFromBool($record, 'disabled', DbCompat::boolFromDb($existing['disabled'] ?? 0));
        if ($type === '' || $name === null || $content === null || $ttl === null || $priority === null || $disabled === null) {
            return $this->returnApiError($label . ': invalid field types in record', 400);
        }
        if ($ttl < 0) {
            return $this->returnApiError($label . ': TTL must not be negative', 400);
        }
        $name = $this->normalizeV2RecordName($name, $zoneName);
        $fqdn = (new HostnameValidator($this->getConfig()))->normalizeRecordName($name, $zoneName);
        if (!$this->apiPermissionService->canRequestZoneRecord($userId, $zoneId, $type, $fqdn, $zoneName)) {
            return $this->returnApiError('You do not have permission to edit this record type', 403);
        }

        // The editor's row shape; an omitted comment keeps the stored one so approval does not clear it
        $row = [
            'rid' => $recordId,
            'zid' => $zoneId,
            'name' => $name,
            'type' => $type,
            'content' => $this->formatV2RecordContent($type, $content),
            'ttl' => (string)$ttl,
            'prio' => (string)$priority,
            'comment' => $this->inputString($record, 'comment') ?? $this->storedComment($recordId, $zoneId, (string)$existing['name'], (string)$existing['type']),
            '_complete' => '1',
        ];
        if ($disabled === 1) {
            $row['disabled'] = 'on';
        }

        return ['op' => $op, 'row' => $row];
    }

    private function storedComment(int|string $recordId, int $zoneId, string $name, string $type): string
    {
        if (!$this->config->get('interface', 'show_record_comments', false)) {
            return '';
        }
        $comment = $this->linkedComments?->findByRecordId($recordId) ?? $this->recordComments->find($zoneId, $name, $type);

        return $comment?->getComment() ?? '';
    }

    /**
     * The 201 for what was filed, or the refusal with the requests filed before it.
     *
     * @param list<ZoneChangeRequestResult> $filed
     * @param list<ZoneChangeRequestResult> $refused
     */
    private function filed(array $filed, array $refused): JsonResponse
    {
        $requests = [];
        foreach ($filed as $result) {
            $request = $result->requestId === null ? null : $this->requests->find($result->requestId);
            if ($request !== null) {
                $requests[] = ChangeRequestsController::serialize($request);
            }
        }

        if ($refused !== []) {
            $refusal = $refused[0];

            return $this->returnApiError($refusal->message, $refusal->status, [
                'errors' => $refusal->errors,
                'change_requests' => $requests,
            ]);
        }

        return $this->returnApiResponse([
            'change_request' => $requests[0] ?? null,
            'change_requests' => $requests,
        ], true, count($requests) === 1 ? 'Change request filed for review.' : sprintf('%d change requests filed for review.', count($requests)), 201);
    }
}
