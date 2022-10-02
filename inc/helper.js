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
    const dbport = document.getElementById("dbport");
    const host = document.getElementById("host");
    const db_name_title = document.getElementById("db_name_title");
    const db_path_title = document.getElementById("db_path_title");
    const username_row = document.getElementById("username_row");
    const password_row = document.getElementById("password_row");
    const hostname_row = document.getElementById("hostname_row");
    const dbport_row = document.getElementById("dbport_row");

    if (db_type === "mysql") {
        dbport.value = "3306";
        host.value = "localhost";
        db_name_title.style.display = '';
        db_path_title.style.display = "none";
        username_row.style.display = '';
        password_row.style.display = '';
        hostname_row.style.display = '';
        dbport_row.style.display = '';
    } else if (db_type === "pgsql") {
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

function disablePasswordField() {
    const ldap = document.getElementById("ldap");
    const password = document.getElementById("password");

    if (ldap.checked) {
        password.value = '';
    }
    password.disabled = ldap.checked;
}

function toggleZoneCheckboxes() {
    const select_state = document.getElementById("select_zones");
    const checkboxes = document.getElementsByName("zone_id[]");
    for (let index=0; index < checkboxes.length; index++){
        checkboxes[index].checked = select_state.checked;
    }
}

function zone_sort_by(sortbytype) {
    console.log(sortbytype);
    document.search_form.zone_sort_by.value = sortbytype;
    document.getElementsByName("do_search")[0].click();
}

function record_sort_by(sortbytype) {
    document.search_form.record_sort_by.value = sortbytype;
    document.getElementsByName("do_search")[0].click();
}
