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

namespace Poweradmin\Domain\Config;

class MailConfigDefaults
{
    public static function getDefaults(): array
    {
        return [
            'mail_enabled' => false,        // Whether to enable mail functionality
            'mail_from' => '',              // Default FROM email address
            'mail_from_name' => '',         // Default FROM name
            'mail_transport' => 'smtp',     // Transport method: smtp, sendmail, or php

            // SMTP settings (only used if mail_transport = 'smtp')
            'smtp_host' => 'localhost',     // SMTP server hostname
            'smtp_port' => 25,              // SMTP server port
            'smtp_encryption' => '',        // SMTP encryption: '', 'ssl', 'tls'
            'smtp_auth' => false,           // Whether SMTP requires authentication
            'smtp_username' => '',          // SMTP username if auth is true
            'smtp_password' => '',          // SMTP password if auth is true
            
            // Email templates
            'password_email_subject' => 'Your new account information',
            'email_signature' => 'DNS Admin',
            'email_title' => 'Your DNS Account Information'
        ];
    }
}
