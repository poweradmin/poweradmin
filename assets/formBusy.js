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

// Shows a spinner on the clicked submit button of forms marked data-busy-submit
// and blocks repeat submits. Saves on a slow backend can take several seconds.
(function () {
    'use strict';

    const busyButtons = [];

    // The submitter keeps its name/value in the payload, so it is never disabled:
    // several controllers branch on the presence of the submit button's name.
    function markBusy(button) {
        if (button.dataset.busyOriginal !== undefined) {
            return;
        }
        button.dataset.busyOriginal = button.innerHTML;

        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm me-1';
        spinner.setAttribute('role', 'status');
        spinner.setAttribute('aria-hidden', 'true');

        const label = document.createElement('span');
        label.textContent = button.dataset.busyText || button.textContent.trim();

        button.replaceChildren(spinner, label);
        button.setAttribute('aria-busy', 'true');
        button.classList.add('pe-none');
        busyButtons.push(button);
    }

    function clearBusy(button) {
        if (button.dataset.busyOriginal === undefined) {
            return;
        }
        button.innerHTML = button.dataset.busyOriginal;
        delete button.dataset.busyOriginal;
        button.removeAttribute('aria-busy');
        button.classList.remove('pe-none');
    }

    // Registered on DOMContentLoaded so this runs after the inline validation
    // handler in footer.html, making event.defaultPrevented meaningful here.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-busy-submit]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (event.defaultPrevented) {
                    return;
                }

                if (form.dataset.busySubmitting === '1') {
                    event.preventDefault();
                    return;
                }
                form.dataset.busySubmitting = '1';

                // event.submitter also resolves buttons bound through a form="" attribute
                const button = event.submitter;
                if (button && button.tagName === 'BUTTON') {
                    markBusy(button);
                }
            });
        });
    });

    // Restoring a bfcache snapshot would otherwise show a stuck spinner
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) {
            return;
        }
        busyButtons.splice(0).forEach(clearBusy);
        document.querySelectorAll('form[data-busy-submit]').forEach(function (form) {
            delete form.dataset.busySubmitting;
        });
    });
}());
