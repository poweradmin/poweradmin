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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Domain\Service\UserContextService;

/**
 * Writes the "client_ip:.. user:.. operation:.." audit lines. Every line
 * starts with the same actor context; one method per event keeps the field
 * names in one place.
 */
class AuditService
{
    private LegacyLogger $logger;
    private IpAddressRetriever $ipRetriever;
    private UserContextService $userContext;

    public function __construct(
        PDO $db,
        ?LegacyLogger $logger = null,
        ?IpAddressRetriever $ipRetriever = null,
        ?UserContextService $userContext = null
    ) {
        $this->logger = $logger ?? new LegacyLogger($db);
        $this->ipRetriever = $ipRetriever ?? new IpAddressRetriever($_SERVER);
        $this->userContext = $userContext ?? new UserContextService();
    }

    private function getContext(): string
    {
        // API requests are stateless, so the actor comes through UserContextService,
        // which names the API principal even when a browser session rides along
        return $this->contextFor($this->userContext->getActingUsername());
    }

    private function contextFor(?string $username): string
    {
        return sprintf('client_ip:%s user:%s', $this->ipRetriever->getClientIp(), $username ?? 'unknown');
    }

    /**
     * @param array<string, int|string|null> $fields Null values are left out of the line
     */
    private function line(string $operation, array $fields = []): string
    {
        return $this->join($this->getContext(), $operation, $fields);
    }

    /**
     * For events logged after the session is gone (single logout), with the
     * actor captured beforehand.
     *
     * @param array<string, int|string|null> $fields
     */
    private function lineAs(?string $username, string $operation, array $fields = []): string
    {
        return $this->join($this->contextFor($username), $operation, $fields);
    }

    /**
     * For flows with no signed-in actor (password reset, username recovery)
     * the line carries the client address only.
     *
     * @param array<string, int|string|null> $fields
     */
    private function anonymousLine(string $operation, array $fields = []): string
    {
        return $this->join('client_ip:' . $this->ipRetriever->getClientIp(), $operation, $fields);
    }

    /** @param array<string, int|string|null> $fields */
    private function join(string $context, string $operation, array $fields): string
    {
        $parts = [$context, 'operation:' . $operation];
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $parts[] = $name . ':' . $value;
            }
        }

        return implode(' ', $parts);
    }

    private static function token(string $value): string
    {
        return str_replace(' ', '_', $value);
    }

    public function logPermTemplateChange(string $targetUser, int $oldTemplateId, int $newTemplateId): void
    {
        $this->logger->logInfo($this->line('perm_template_change', [
            'target_user' => $targetUser,
            'old_template' => $oldTemplateId,
            'new_template' => $newTemplateId,
        ]));
    }

    public function logSessionExpired(): void
    {
        $this->logger->logNotice($this->line('session_expired'));
    }

    public function logAccessDenied(string $permission, string $requestUri): void
    {
        $this->logger->logWarn($this->line('access_denied', ['permission' => $permission, 'uri' => $requestUri]));
    }

    // Accounts

    public function logUserAdd(string $username, string $email): void
    {
        $this->logger->logInfo($this->line('add_user', ['username' => $username, 'email' => $email]));
    }

    public function logUserEdit(string $targetUser, int $permTemplateId, string $authType): void
    {
        $this->logger->logInfo($this->line('edit_user', [
            'target_user' => $targetUser,
            'perm_template' => $permTemplateId,
            'auth_type' => $authType,
        ]));
    }

    public function logUserDelete(string $targetUser): void
    {
        $this->logger->logInfo($this->line('delete_user', ['target_user' => $targetUser]));
    }

    public function logPasswordChange(): void
    {
        $this->logger->logInfo($this->line('change_password'));
    }

    public function logLogout(): void
    {
        $this->logger->logInfo($this->line('logout'));
    }

    public function logPasswordResetRequest(string $email): void
    {
        $this->logger->logInfo($this->anonymousLine('password_reset_request', ['email' => $email]));
    }

    public function logPasswordReset(int $userId): void
    {
        $this->logger->logInfo($this->anonymousLine('password_reset', ['user_id' => $userId]));
    }

    public function logUsernameRecovery(string $email): void
    {
        $this->logger->logInfo($this->anonymousLine('username_recovery', ['email' => $email]));
    }

    // Sign-in

    public function logMfaEnable(string $mfaType): void
    {
        $this->logger->logInfo($this->line('mfa_enable', ['mfa_type' => $mfaType]));
    }

    public function logMfaDisable(): void
    {
        $this->logger->logInfo($this->line('mfa_disable'));
    }

    public function logMfaRecoveryCodesRegenerate(): void
    {
        $this->logger->logInfo($this->line('mfa_regenerate_codes'));
    }

    public function logMfaVerify(string $mfaType): void
    {
        $this->logger->logInfo($this->line('mfa_verify', ['mfa_type' => $mfaType]));
    }

    public function logMfaFailed(string $mfaType): void
    {
        $this->logger->logWarn($this->line('mfa_failed', ['mfa_type' => $mfaType]));
    }

    /**
     * An identity-provider handshake error. Logged as login_error, not
     * login_failed, so it does not feed fail2ban brute-force counters.
     */
    public function logSsoLoginError(string $authMethod, string $error): void
    {
        $this->logger->logWarn($this->anonymousLine('login_error', ['auth_method' => $authMethod, 'error' => $error]));
    }

    public function logSsoLoginSuccess(string $authMethod): void
    {
        $this->logger->logInfo($this->line($authMethod . '_login_success'));
    }

    /**
     * @param string|null $username Captured before SLO processing, which clears the session
     */
    public function logSamlLogout(?string $username): void
    {
        $this->logger->logInfo($this->lineAs($username, 'saml_logout'));
    }

    // API keys

    public function logApiKeyCreate(int $keyId, string $keyName): void
    {
        $this->apiKeyEvent('api_key_create', $keyId, $keyName);
    }

    public function logApiKeyEdit(int $keyId, string $keyName): void
    {
        $this->apiKeyEvent('api_key_edit', $keyId, $keyName);
    }

    public function logApiKeyDelete(int $keyId, string $keyName): void
    {
        $this->apiKeyEvent('api_key_delete', $keyId, $keyName);
    }

    public function logApiKeyRegenerate(int $keyId, string $keyName): void
    {
        $this->apiKeyEvent('api_key_regenerate', $keyId, $keyName);
    }

    public function logApiKeyToggle(int $keyId, string $keyName, bool $disabled): void
    {
        $this->apiKeyEvent('api_key_toggle', $keyId, $keyName, ['status' => $disabled ? 'disabled' : 'enabled']);
    }

    /** @param array<string, int|string|null> $extra */
    private function apiKeyEvent(string $operation, int $keyId, string $keyName, array $extra = []): void
    {
        $this->logger->logApiInfo($this->line($operation, ['key_id' => $keyId, 'key_name' => $keyName] + $extra));
    }

    // Permission templates

    public function logPermTemplateAdd(string $name): void
    {
        $this->logger->logInfo($this->line('add_perm_template', ['name' => $name]));
    }

    public function logPermTemplateEdit(int $templateId, string $name): void
    {
        $this->logger->logInfo($this->line('edit_perm_template', ['id' => $templateId, 'name' => $name]));
    }

    public function logPermTemplateDelete(int $templateId, string $name): void
    {
        $this->logger->logInfo($this->line('delete_perm_template', ['id' => $templateId, 'name' => $name]));
    }

    // Zones

    public function logZoneAdd(int $zoneId, string $zoneName, string $zoneType, ?string $template = null, ?string $master = null): void
    {
        $this->logger->logInfo($this->line('add_zone', [
            'zone' => $zoneName,
            'zone_type' => $zoneType,
            'zone_template' => $template,
            'zone_master' => $master,
        ]), $zoneId);
    }

    public function logZoneDelete(int $zoneId, string $zoneName, string $zoneType): void
    {
        $this->logger->logInfo($this->line('delete_zone', ['zone' => $zoneName, 'zone_type' => $zoneType]), $zoneId);
    }

    /**
     * A zone file import: a new zone or records added to an existing one.
     */
    public function logZoneImport(int $zoneId, string $zoneName, bool $intoExistingZone): void
    {
        $operation = $intoExistingZone ? 'zone_import_records' : 'zone_import';
        $this->logger->logInfo($this->line($operation, ['zone_name' => $zoneName]), $zoneId);
    }

    public function logSecondaryZoneImport(int $zoneId, string $zoneName, string $master): void
    {
        $this->logger->logInfo($this->line('import_secondary_zone', ['zone' => $zoneName, 'zone_master' => $master]), $zoneId);
    }

    public function logApiZoneAdd(int $zoneId, string $zoneName, string $zoneType): void
    {
        $this->logger->logInfo($this->line('api_add_zone', ['zone_name' => $zoneName, 'zone_type' => $zoneType]), $zoneId);
    }

    public function logApiZoneDelete(int $zoneId, string $zoneName): void
    {
        $this->logger->logInfo($this->line('api_delete_zone', ['zone_name' => $zoneName]), $zoneId);
    }

    // Records

    public function logRecordAdd(int $zoneId, string $type, string $name, string $content, int|string $ttl, int|string $prio): void
    {
        $this->logger->logInfo($this->line('add_record', [
            'record_type' => $type,
            'record' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => $prio,
        ]), $zoneId);
    }

    /**
     * @param array<string, mixed> $before Record row before the edit (type, name, content, ttl, prio)
     * @param array<string, mixed> $after Record row after the edit
     */
    public function logRecordEdit(?int $zoneId, array $before, array $after): void
    {
        $this->logger->logInfo($this->line('edit_record', [
            'old_record_type' => $before['type'] ?? '',
            'old_record' => $before['name'] ?? '',
            'old_content' => $before['content'] ?? '',
            'old_ttl' => $before['ttl'] ?? '',
            'old_priority' => $before['prio'] ?? '',
            'record_type' => $after['type'] ?? '',
            'record' => $after['name'] ?? '',
            'content' => $after['content'] ?? '',
            'ttl' => $after['ttl'] ?? '',
            'priority' => $after['prio'] ?? '',
        ]), $zoneId);
    }

    public function logRecordDelete(int $zoneId, string $type, string $name, string $content, int|string $ttl, int|string|null $prio): void
    {
        $this->logger->logInfo($this->line('delete_record', [
            'record_type' => $type,
            'record' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => $prio,
        ]), $zoneId);
    }

    public function logBatchPtrRecordAdd(int $zoneId, string $name, string $content, int|string $ttl, int|string $prio): void
    {
        $this->logger->logInfo($this->line('add_batch_ptr_record', [
            'record_type' => 'PTR',
            'record' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => $prio,
        ]), $zoneId);
    }

    public function logRecordTypeDefaultSave(string $type, int $ttl): void
    {
        $this->logger->logInfo($this->line('save_record_type_default', ['record_type' => $type, 'ttl' => $ttl]));
    }

    public function logRecordTypeDefaultDelete(string $type): void
    {
        $this->logger->logInfo($this->line('delete_record_type_default', ['record_type' => $type]));
    }

    public function logApiRecordAdd(int $zoneId, string $name, string $type, string $content): void
    {
        $this->logger->logInfo($this->line('api_add_record', ['name' => $name, 'type' => $type, 'content' => $content]), $zoneId);
    }

    public function logApiRecordEdit(int $zoneId, string $name, string $type, string $content): void
    {
        $this->logger->logInfo($this->line('api_edit_record', ['name' => $name, 'type' => $type, 'content' => $content]), $zoneId);
    }

    public function logApiRecordDelete(int $zoneId, string $name, string $type, string $content): void
    {
        $this->logger->logInfo($this->line('api_delete_record', ['name' => $name, 'type' => $type, 'content' => $content]), $zoneId);
    }

    public function logApiRrsetReplace(int $zoneId, string $name, string $type, int $records): void
    {
        $this->logger->logInfo($this->line('api_replace_rrset', ['name' => $name, 'type' => $type, 'records' => $records]), $zoneId);
    }

    public function logApiRrsetDelete(int $zoneId, string $name, string $type, int $records): void
    {
        $this->logger->logInfo($this->line('api_delete_rrset', ['name' => $name, 'type' => $type, 'records' => $records]), $zoneId);
    }

    public function logApiBulkRecords(int $zoneId, int $operations): void
    {
        $this->logger->logInfo($this->line('api_bulk_records', ['operations' => $operations]), $zoneId);
    }

    // Zone ownership

    /**
     * Logged against the member zone: that is the zone whose stored catalog changed.
     */
    public function logZoneCatalogAssign(int $zoneId, string $zoneName, string $catalog): void
    {
        $this->logger->logInfo($this->line('zone_catalog_assign', ['zone' => $zoneName, 'catalog' => $catalog]), $zoneId);
    }

    public function logZoneCatalogClear(int $zoneId, string $zoneName, string $catalog): void
    {
        $this->logger->logInfo($this->line('zone_catalog_clear', ['zone' => $zoneName, 'catalog' => $catalog]), $zoneId);
    }

    public function logZoneOwnerAdd(int $zoneId, string $zoneName, int $ownerId): void
    {
        $this->logger->logInfo($this->line('zone_owner_add', ['zone' => $zoneName, 'owner_id' => $ownerId]), $zoneId);
    }

    public function logZoneOwnerRemove(int $zoneId, string $zoneName, int $ownerId): void
    {
        $this->logger->logInfo($this->line('zone_owner_remove', ['zone' => $zoneName, 'owner_id' => $ownerId]), $zoneId);
    }

    public function logZoneGroupAdd(int $zoneId, string $zoneName, int $groupId): void
    {
        $this->logger->logInfo($this->line('zone_group_add', ['zone' => $zoneName, 'group_id' => $groupId]), $zoneId);
    }

    public function logZoneGroupRemove(int $zoneId, string $zoneName, int $groupId): void
    {
        $this->logger->logInfo($this->line('zone_group_remove', ['zone' => $zoneName, 'group_id' => $groupId]), $zoneId);
    }

    // DNSSEC

    public function logDnssecAddKey(int $zoneId, string $zoneName, string $keyType, string $bits, string $algorithm): void
    {
        $this->logger->logInfo($this->line('dnssec_add_key', [
            'zone' => $zoneName,
            'key_type' => $keyType,
            'bits' => $bits,
            'algorithm' => $algorithm,
        ]), $zoneId);
    }

    public function logDnssecDeleteKey(int $zoneId, string $zoneName, int $keyId): void
    {
        $this->logger->logInfo($this->line('dnssec_delete_key', ['zone' => $zoneName, 'key_id' => $keyId]), $zoneId);
    }

    public function logDnssecToggleKey(int $zoneId, string $zoneName, int $keyId, string $action): void
    {
        $this->logger->logInfo($this->line('dnssec_toggle_key', ['zone' => $zoneName, 'key_id' => $keyId, 'action' => $action]), $zoneId);
    }

    /**
     * @param list<string> $kinds The kinds the zone carries after the edit
     */
    public function logZoneMetadataEdit(int $zoneId, string $zoneName, array $kinds): void
    {
        $this->logger->logInfo($this->line('edit_zone_metadata', ['zone' => $zoneName, 'kinds' => implode(',', $kinds)]), $zoneId);
    }

    public function logDnssecSignZone(int $zoneId, string $zoneName): void
    {
        $this->logger->logInfo($this->line('dnssec_sign_zone', ['zone' => $zoneName]), $zoneId);
    }

    public function logDnssecUnsignZone(int $zoneId, string $zoneName): void
    {
        $this->logger->logInfo($this->line('dnssec_unsign_zone', ['zone' => $zoneName]), $zoneId);
    }

    // Groups

    public function logGroupCreate(int $groupId, string $groupName, string $templateName, int $templateId): void
    {
        $this->logger->logGroupInfo($this->line('create_group', [
            'group' => self::token($groupName),
            'group_id' => $groupId,
            'perm_template' => $templateName,
            'perm_template_id' => $templateId,
        ]), $groupId);
    }

    /**
     * @param list<string> $changes Human-readable "field: old -> new" entries
     */
    public function logGroupEdit(int $groupId, string $groupName, array $changes): void
    {
        $this->logger->logGroupInfo($this->line('edit_group', [
            'group' => self::token($groupName),
            'group_id' => $groupId,
            'changes' => implode('; ', $changes),
        ]), $groupId);
    }

    /**
     * Written to the general log: the group row is gone, so there is nothing to attach it to.
     */
    public function logGroupDelete(int $groupId, string $groupName, int $membersAffected, int $zonesAffected): void
    {
        $this->logger->logGroupWarning($this->line('delete_group', [
            'group' => self::token($groupName),
            'group_id' => $groupId,
            'members_affected' => $membersAffected,
            'zones_affected' => $zonesAffected,
        ]), null);
    }

    /** @param list<string> $usernames */
    public function logGroupMembersAdd(int $groupId, string $groupName, array $usernames): void
    {
        $this->logger->logGroupInfo($this->groupListLine('add_members', $groupId, $groupName, 'members', $usernames), $groupId);
    }

    /** @param list<string> $usernames */
    public function logGroupMembersRemove(int $groupId, string $groupName, array $usernames): void
    {
        $this->logger->logGroupInfo($this->groupListLine('remove_members', $groupId, $groupName, 'members', $usernames), $groupId);
    }

    public function logApiGroupMemberAdd(int $groupId, string $groupName, string $username): void
    {
        $this->logger->logGroupInfo($this->groupListLine('api_add_members', $groupId, $groupName, 'members', [$username]), $groupId);
    }

    /** @param list<string> $zoneNames */
    public function logGroupZonesAdd(int $groupId, string $groupName, array $zoneNames): void
    {
        $this->logger->logGroupInfo($this->groupListLine('add_zones', $groupId, $groupName, 'zones', $zoneNames), $groupId);
    }

    /** @param list<string> $zoneNames */
    public function logGroupZonesRemove(int $groupId, string $groupName, array $zoneNames): void
    {
        $this->logger->logGroupInfo($this->groupListLine('remove_zones', $groupId, $groupName, 'zones', $zoneNames), $groupId);
    }

    public function logGroupMemberRemove(int $groupId, int $userId): void
    {
        $this->logger->logGroupInfo($this->line('remove_members', ['group_id' => $groupId, 'user_id' => $userId]), $groupId);
    }

    /** @param list<string> $names The members or zones the operation touched */
    private function groupListLine(string $operation, int $groupId, string $groupName, string $listField, array $names): string
    {
        return $this->line($operation, [
            'group' => self::token($groupName),
            'group_id' => $groupId,
            'count' => count($names),
            $listField => implode(',', $names),
        ]);
    }

    // Zone templates

    public function logZoneTemplateAdd(string $templateName): void
    {
        $this->logger->logInfo($this->line('add_zone_template', ['template_name' => self::token($templateName)]));
    }

    public function logZoneTemplateEdit(int $templateId, string $templateName): void
    {
        $this->logger->logInfo($this->line('edit_zone_template', [
            'template_id' => $templateId,
            'template_name' => self::token($templateName),
        ]));
    }

    public function logZoneTemplateDelete(int $templateId): void
    {
        $this->logger->logInfo($this->line('delete_zone_template', ['template_id' => $templateId]));
    }

    public function logZoneTemplateUnlink(int $zoneId): void
    {
        $this->logger->logInfo($this->line('unlink_zone_template', ['zone_id' => $zoneId]), $zoneId);
    }

    // Zone template records

    public function logZoneTemplateRecordAdd(int $templateId, string $recordName, string $recordType): void
    {
        $this->logger->logInfo($this->line('add_zone_template_record', [
            'template_id' => $templateId,
            'record_name' => self::token($recordName),
            'record_type' => $recordType,
        ]));
    }

    public function logZoneTemplateRecordEdit(int $templateId, int $recordId, string $recordName, string $recordType): void
    {
        $this->logger->logInfo($this->line('edit_zone_template_record', [
            'template_id' => $templateId,
            'record_id' => $recordId,
            'record_name' => self::token($recordName),
            'record_type' => $recordType,
        ]));
    }

    public function logZoneTemplateRecordDelete(int $templateId, int $recordId): void
    {
        $this->logger->logInfo($this->line('delete_zone_template_record', ['template_id' => $templateId, 'record_id' => $recordId]));
    }

    public function logApiZoneTemplateAdd(int $templateId, string $templateName): void
    {
        $this->logger->logInfo($this->line('api_add_zone_template', [
            'template_id' => $templateId,
            'template_name' => self::token($templateName),
        ]));
    }

    public function logApiZoneTemplateEdit(int $templateId, string $templateName): void
    {
        $this->logger->logInfo($this->line('api_edit_zone_template', [
            'template_id' => $templateId,
            'template_name' => self::token($templateName),
        ]));
    }

    public function logApiZoneTemplateDelete(int $templateId): void
    {
        $this->logger->logInfo($this->line('api_delete_zone_template', ['template_id' => $templateId]));
    }

    public function logApiZoneTemplateRecordAdd(int $templateId, int $recordId, string $recordName, string $recordType): void
    {
        $this->logger->logInfo($this->line('api_add_zone_template_record', [
            'template_id' => $templateId,
            'record_id' => $recordId,
            'record_name' => self::token($recordName),
            'record_type' => $recordType,
        ]));
    }

    public function logApiZoneTemplateRecordEdit(int $templateId, int $recordId, string $recordName, string $recordType): void
    {
        $this->logger->logInfo($this->line('api_edit_zone_template_record', [
            'template_id' => $templateId,
            'record_id' => $recordId,
            'record_name' => self::token($recordName),
            'record_type' => $recordType,
        ]));
    }

    public function logApiZoneTemplateRecordDelete(int $templateId, int $recordId): void
    {
        $this->logger->logInfo($this->line('api_delete_zone_template_record', ['template_id' => $templateId, 'record_id' => $recordId]));
    }

    // Zone comments

    public function logZoneCommentEdit(int $zoneId, string $zoneName): void
    {
        $this->logger->logInfo($this->line('edit_zone_comment', ['zone' => $zoneName]), $zoneId);
    }

    // Supermasters

    public function logSupermasterAdd(string $masterIp, string $nsName): void
    {
        $this->logger->logInfo($this->line('add_supermaster', ['master_ip' => $masterIp, 'ns_name' => $nsName]));
    }

    public function logSupermasterEdit(string $oldMasterIp, string $oldNsName, string $newMasterIp, string $newNsName): void
    {
        $this->logger->logInfo($this->line('edit_supermaster', [
            'old_master_ip' => $oldMasterIp,
            'old_ns_name' => $oldNsName,
            'new_master_ip' => $newMasterIp,
            'new_ns_name' => $newNsName,
        ]));
    }

    public function logSupermasterDelete(string $masterIp, string $nsName): void
    {
        $this->logger->logInfo($this->line('delete_supermaster', ['master_ip' => $masterIp, 'ns_name' => $nsName]));
    }
}
