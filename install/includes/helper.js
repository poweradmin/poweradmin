/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

function updateFormFields(db_type)
{
    const db_name_title = document.getElementById('db_name_title');
    const db_path_title = document.getElementById('db_path_title');
    const username_row = document.getElementById('username_row');
    const username = document.getElementById('username');
    const password_row = document.getElementById('password_row');
    const userpass = document.getElementById('userpass');
    const hostname_row = document.getElementById('hostname_row');
    const host = document.getElementById('host');
    const dbport_row = document.getElementById('dbport_row');
    const db_port = document.getElementById('db_port');
    const db_charset_row = document.getElementById('db_charset_row');
    const db_collation_row = document.getElementById('db_collation_row');

    switch (db_type) {
        case 'mysql':
            if (db_port.value === "") {
                db_port.value = "3306";
            }
            if (host.value === "") {
                host.value = 'localhost';
            }

            db_name_title.style.display = '';
            db_path_title.style.display = 'none';
            username_row.style.display = '';
            password_row.style.display = '';
            hostname_row.style.display = '';
            dbport_row.style.display = '';
            db_charset_row.style.display = '';
            db_collation_row.style.display = '';

            username.required = true;
            userpass.required = true;
            host.required = true;
            db_port.required = true;
            break;

        case 'pgsql':
            if (db_port.value === "") {
                db_port.value = '5432';
            }
            if (host.value === "") {
                host.value = 'localhost';
            }

            db_name_title.style.display = '';
            db_path_title.style.display = 'none';
            username_row.style.display = '';
            password_row.style.display = '';
            hostname_row.style.display = '';
            dbport_row.style.display = '';
            db_charset_row.style.display = '';
            db_collation_row.style.display = '';

            username.required = true;
            userpass.required = true;
            host.required = true;
            db_port.required = true;
            break;

        default: // SQLite
            db_port.value = "";
            host.value = "";

            db_name_title.style.display = 'none';
            db_path_title.style.display = '';
            username_row.style.display = 'none';
            password_row.style.display = 'none';
            hostname_row.style.display = 'none';
            dbport_row.style.display = 'none';
            db_charset_row.style.display = 'none';
            db_collation_row.style.display = 'none';

            username.required = false;
            userpass.required = false;
            host.required = false;
            db_port.required = false;
            break;
    }
}

function showPassword(passwordInputId, iconId)
{
    const password = document.getElementById(passwordInputId);
    const icon = document.getElementById(iconId);
    password.type = password.type === "password" ? "text" : "password";
    icon.classList.toggle("bi-eye-fill");
    icon.classList.toggle("bi-eye-slash-fill");
}
