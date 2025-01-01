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
    for (let index = 0; index < checkboxes.length; index++) {
        checkboxes[index].checked = select_state.checked;
    }
}

function zone_sort_by(column) {
    const form = document.search_form;
    const currentSortBy = form.zone_sort_by.value;
    const currentSortDirection = form.zone_sort_by_direction.value;

    if (currentSortBy === column) {
        form.zone_sort_by_direction.value = currentSortDirection === 'ASC' ? 'DESC' : 'ASC';
    } else {
        form.zone_sort_by.value = column;
        form.zone_sort_by_direction.value = 'ASC';
    }

    form.submit();
}

function record_sort_by(column) {
    const form = document.search_form;
    const currentSortBy = form.record_sort_by.value;
    const currentSortDirection = form.record_sort_by_direction.value;

    if (currentSortBy === column) {
        form.record_sort_by_direction.value = currentSortDirection === 'ASC' ? 'DESC' : 'ASC';
    } else {
        form.record_sort_by.value = column;
        form.record_sort_by_direction.value = 'ASC';
    }

    form.submit();
}

function do_search_with_zones_page(zones_page) {
    document.search_form.zones_page.value = zones_page;
    document.getElementsByName("do_search")[0].click();
}

function do_search_with_records_page(records_page) {
    document.search_form.records_page.value = records_page;
    document.getElementsByName("do_search")[0].click();
}

const queryState = (() => {
    let previousQuery = '';

    return {
        getPreviousQuery: function() {
            return previousQuery;
        },
        setPreviousQuery: function(value) {
            previousQuery = value;
        }
    };
})();

function checkQueryChange(form) {
    const currentQuery = form.querySelector('input[name="query"]').value;

    if (queryState.getPreviousQuery() !== currentQuery) {
        form.querySelector('input[name="zones_page"]').value = 1;
        form.querySelector('input[name="records_page"]').value = 1;
    }
}

function showPassword(passwordInputId, iconId) {
    const password = document.getElementById(passwordInputId);
    const icon = document.getElementById(iconId);
    password.type = password.type === "password" ? "text" : "password";
    icon.classList.toggle("bi-eye-fill");
    icon.classList.toggle("bi-eye-slash-fill");
}