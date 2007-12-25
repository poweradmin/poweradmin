<?php

/*  PowerAdmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007, 2008  Rejo Zenger <rejo@zenger.nl>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

include_once("inc/config.inc.php");
include_once("inc/header.inc.php");
?>
    <h2><? echo _('Credits'); ?></h2>

    <p><a href="https://rejo.zenger.nl/poweradmin/">This version</a> is an adaption of the original <a href="http://www.poweradmin.org">Poweradmin</a>, version 1.2.7-patched. Poweradmin was written by <a href="http://sjeemz.nl/">Sjeemz</a> and <a href="http://www.trancer.nl/">Trancer</a>. The Poweradmin code includes patches by <a href="http://mostrey.be/">Wim Mostrey</a> and Dennis Roos. <a href="https://rejo.zenger.nl/poweradmin/">This version</a> has been patched by <a href="http://rejo.zenger.nl">Rejo Zenger</a> and includes many additional features like multi-language support, an update of the database abstraction layer, support for slave zones, support for supermasters, basic support for skins and a number of bug fixes.</p>

    <p>Thanks to Peter Beernink, Koert Buijze, Jasper van Erven Dorens, John Morris and Balazs Petrikovics for bug reports, patches and good ideas.</p>

<?
include_once("inc/footer.inc.php");
?>
