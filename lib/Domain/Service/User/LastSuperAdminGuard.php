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

namespace Poweradmin\Domain\Service\User;

use Throwable;
use Poweradmin\Domain\Model\UberuserChange;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\UserPermissionReadInterface;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * The last-super-admin rule for writes that take super admin away from users without
 * touching their account: template contents, group membership, group deletion and a
 * group's template. Each one decides and writes in one transaction holding the super
 * admin rows, so two concurrent changes cannot both pass.
 */
class LastSuperAdminGuard
{
    public const ERR_LAST_ADMIN_DEMOTE = UserManagementService::ERR_LAST_ADMIN_DEMOTE;
    public const MESSAGE = 'Cannot remove super admin from the last remaining super admin user. At least one active super admin must exist in the system.';

    public function __construct(
        private readonly UserPermissionReadInterface $users,
        private readonly ?TransactionInterface $transaction = null
    ) {
    }

    /**
     * Writes unless $change would leave no active super admin. Returns the refusal, or
     * what $write returned.
     *
     * @template T
     * @param callable(): T $write
     * @return array{success: false, message: string, refusal: Refusal, code: string}|T
     */
    public function guard(UberuserChange $change, callable $write): mixed
    {
        return $this->underLock(fn(): mixed => $this->refusal($change) ?? $write());
    }

    /**
     * The refusal when $change would leave no active super admin; meant to run under underLock().
     *
     * @return array{success: false, message: string, refusal: Refusal, code: string}|null
     */
    public function refusal(UberuserChange $change): ?array
    {
        if ($this->users->countUberusersAfter($change) > 0) {
            return null;
        }

        // With none left already, this change is not the one that removes the last
        if ($this->users->countUberusersAfter(UberuserChange::none()) === 0) {
            return null;
        }

        return [
            'success' => false,
            'message' => self::MESSAGE,
            'refusal' => Refusal::CONFLICT,
            'code' => self::ERR_LAST_ADMIN_DEMOTE,
        ];
    }

    /**
     * Runs a last-admin check and the write it protects in one transaction. A refusal
     * array or false rolls back, anything else commits. Without a transaction port it runs bare.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function underLock(callable $work): mixed
    {
        if ($this->transaction === null) {
            return $work();
        }

        // An outer transaction owns its own commit; this one only joins it.
        $owned = !$this->transaction->inTransaction();
        if ($owned) {
            $this->transaction->begin();
        }

        try {
            $result = $work();
            if ($owned) {
                (is_array($result) || $result === false) ? $this->transaction->rollBack() : $this->transaction->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($owned && $this->transaction->inTransaction()) {
                $this->transaction->rollBack();
            }
            throw $e;
        }
    }
}
