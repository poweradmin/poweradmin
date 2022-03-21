/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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

function changePort(db_type) {
    var dbport = document.getElementById("dbport");
    var host = document.getElementById("host");
    var db_name_title = document.getElementById("db_name_title");
    var db_path_title = document.getElementById("db_path_title");
    var username_row = document.getElementById("username_row");
    var password_row = document.getElementById("password_row");
    var hostname_row = document.getElementById("hostname_row");
    var dbport_row = document.getElementById("dbport_row");

    if (db_type == "mysql") {
        dbport.value = "3306";
        host.value = "localhost";
        db_name_title.style.display = '';
        db_path_title.style.display = "none";
        username_row.style.display = '';
        password_row.style.display = '';
        hostname_row.style.display = '';
        dbport_row.style.display = '';
    } else if (db_type == "pgsql") {
        dbport.value = "5432";
        host.value = "localhost";
        db_name_title.style.display = '';
        db_path_title.style.display = "none";
        username_row.style.display = '';
        password_row.style.display = '';
        hostname_row.style.display = '';
        dbport_row.style.display = '';
    } else {
        dbport.value = "";
        host.value = "";
        db_name_title.style.display = "none";
        db_path_title.style.display = '';
        username_row.style.display = "none";
        password_row.style.display = "none";
        hostname_row.style.display = "none";
        dbport_row.style.display = "none";
    }
}

function getDomainsElements() {
    var
            coll = document.getElementsByTagName('input'),
            re = /^domain\[\]$/,
            t,
            elm,
            i = 0,
            key = 0,
            records = [];

    while (elm = coll.item(i++))
    {
        t = re.exec(elm.name);
        if (t != null)
        {
            records[key] = elm;
            key++;
        }
    }
    return records;
}

function checkDomainFilled() {
    var
            allEmpty = true,
            domains = getDomainsElements();

    if (domains.length == 1) {
        if ((domains[0].value.length == 0 || domains[0].value == null || domains[0].value == "")) {
            alert('Zone name cannot be empty');
            return false;
        }
    } else {
        for (var key in domains) {
            if ((domains[key].value.length != 0)) {
                allEmpty = false;
            }
        }

        if (true === allEmpty) {
            alert('Please fill in at least one Zone name');
            return false;
        }
    }

    add_zone_master.submit();
}

function disablePasswordField() {
    const ldap = document.getElementById("ldap");
    const password = document.getElementById("password");

    if (ldap.checked) {
        password.value = '';
    }
    password.disabled = ldap.checked;
}