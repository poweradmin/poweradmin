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

namespace Poweradmin\Domain\Model;

/**
 * Value object representing a DNS record prepared for display
 * Encapsulates both the raw record data and display-specific transformations
 */
class RecordDisplay
{
    private array $recordData;
    private string $displayName;
    private string $editableName;
    private bool $isHostnameOnly;

    public function __construct(
        array $recordData,
        string $displayName,
        string $editableName,
        bool $isHostnameOnly = false
    ) {
        $this->recordData = $recordData;
        $this->displayName = $displayName;
        $this->editableName = $editableName;
        $this->isHostnameOnly = $isHostnameOnly;
    }

    /**
     * Get the display name (what users see in the UI)
     */
    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * Get the editable name (what appears in form inputs)
     */
    public function getEditableName(): string
    {
        return $this->editableName;
    }

    /**
     * Get the original record ID
     */
    public function getId(): int
    {
        return (int) $this->recordData['id'];
    }

    /**
     * Get the original record name (FQDN)
     */
    public function getName(): string
    {
        return $this->recordData['name'];
    }

    /**
     * Get the record type
     */
    public function getType(): string
    {
        return $this->recordData['type'];
    }

    /**
     * Get the record content
     */
    public function getContent(): string
    {
        return $this->recordData['content'];
    }

    /**
     * Get the record TTL
     */
    public function getTtl(): int
    {
        return (int) $this->recordData['ttl'];
    }

    /**
     * Get the record priority
     */
    public function getPriority(): ?int
    {
        return isset($this->recordData['prio']) ? (int) $this->recordData['prio'] : null;
    }

    /**
     * Get the record comment
     */
    public function getComment(): ?string
    {
        return $this->recordData['comment'] ?? null;
    }

    /**
     * Check if record is disabled
     */
    public function isDisabled(): bool
    {
        return isset($this->recordData['disabled']) && $this->recordData['disabled'] == '1';
    }

    /**
     * Check if hostname-only display is active
     */
    public function isHostnameOnly(): bool
    {
        return $this->isHostnameOnly;
    }

    /**
     * Get all raw record data
     */
    public function getRawData(): array
    {
        return $this->recordData;
    }

    /**
     * Convert to array for template consumption
     */
    public function toArray(): array
    {
        return array_merge($this->recordData, [
            'display_name' => $this->displayName,
            'editable_name' => $this->editableName,
            'is_hostname_only' => $this->isHostnameOnly,
        ]);
    }
}
