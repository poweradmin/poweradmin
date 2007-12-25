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

include_once("../inc/config.inc.php");
$bgcolor = "#FCC229"; //Original style
?>


A:link { color: #000000}
A:visited { color: #000000}
A:active { color: #000000}
A:hover {text-decoration: none}
BODY {font-family: Verdana, Arial, Helvetica; background-image: url("<?= $GLOBALS["BASE_URL"].$GLOBALS["BASE_PATH"] ?>images/background.jpg");}
TABLE {background-color: <?= $bgcolor ?>; border: 1px solid #000000; width: 900px;}
TD {background-color: White; font-size: 12px;}
TR {background-color: <?= $bgcolor ?>}
.TDBG {
        background-color: <?= $bgcolor ?>;
}
.ERROR {
        background-color: #FF0000;
        border: 1px solid;
        width: 600px;
}
.MESSAGETABLE {
        background-color: <?= $bgcolor ?>;
        border: 1px solid;
        width: 600px;
}

.MESSAGE {
        background-color: <?= $bgcolor ?>;
        width: 600px;
}
.NONE {
        background-color: transparent;
        border: none;
        width: 0px;
}
.TEXT {
	background-color: transparent !important; 
	border: 0px; 
}
.SBUTTON {
        BORDER-BOTTOM: #999999 1px solid;
        BORDER-LEFT: #999999 1px solid;
        BORDER-RIGHT: #999999 1px solid;
        BORDER-TOP: #999999 1px solid;
        BACKGROUND-COLOR: <?= $bgcolor ?>;
        COLOR: #000000;
        BORDER-COLOR: #000000;
        FONT-FAMILY: Verdana;
        FONT-WEIGHT: Bold;
        FONT-SIZE: 10px;
        WIDTH MENARU: 60px;
}
.BUTTON {
        BORDER-BOTTOM: #999999 1px solid;
        BORDER-LEFT: #999999 1px solid;
        BORDER-RIGHT: #999999 1px solid;
        BORDER-TOP: #999999 1px solid;
        BACKGROUND-COLOR: <?= $bgcolor ?>;
        COLOR: #000000;
        BORDER-COLOR: #000000;
        FONT-FAMILY: Verdana;
        FONT-WEIGHT: Bold;
        FONT-SIZE: 10px;
        WIDTH MENARU: 120px;
}
.INPUT {
        BORDER-BOTTOM: #999999 1px solid;
        BORDER-LEFT: #999999 1px solid;
        BORDER-RIGHT: #999999 1px solid;
        BORDER-TOP: #999999 1px solid;
        BACKGROUND-COLOR: #FFFFFF;

        COLOR: #000000;
        BORDER-COLOR: #000000;
        FONT-FAMILY: Verdana;
        FONT-SIZE: 11px;
        WIDTH MENARU: 180px;
}
.SINPUT {
        BORDER-BOTTOM: #999999 1px solid;
        BORDER-LEFT: #999999 1px solid;
        BORDER-RIGHT: #999999 1px solid;
        BORDER-TOP: #999999 1px solid;
        BACKGROUND-COLOR: #FFFFFF;
        COLOR: #000000;
        BORDER-COLOR: #000000;
        FONT-FAMILY: Verdana;
        FONT-SIZE: 11px;
        WIDTH MENARU: 40px;
}
.WARNING {
        color: #FF0000;
        font-weight: Bold;
}
.FOOTER {
        font-size: 10px;
}
.ACTIVE {
        color: #669933;
        font-weight: Bold;
}
.INACTIVE {
        color: #FF0000;
        font-weight: Bold;
}
.NAV {
        color: #0000FF;
        font-weight: Bold;
        A:link { color: #0000FF}
        A:visited { color: #0000FF}
        A:active { color: #0000FF}
        A:hover {text-decoration: none}
}
.inputarea {
        BORDER-BOTTOM: #999999 1px solid;
        BORDER-LEFT: #999999 1px solid;
        BORDER-RIGHT: #999999 1px solid;
        BORDER-TOP: #999999 1px solid;
        BACKGROUND-COLOR: #FFFFFF;
        COLOR: #000000;
        BORDER-COLOR: #000000;
        FONT-FAMILY: Verdana;
        FONT-SIZE: 11px;
        WIDTH MENARU: 300px;
        HEIGHT MENARU: 100px;
}
