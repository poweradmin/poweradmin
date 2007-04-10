<?php
// +--------------------------------------------------------------------+
// | PowerAdmin								|
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team			|
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal	|
// | program PowerAdmin as found on http://poweradmin.sf.net		|
// | The PowerAdmin program falls under the QPL License:		|
// | http://www.trolltech.com/developer/licensing/qpl.html		|
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>	|
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>			|
// +--------------------------------------------------------------------+

//
// $Id: style.css.php,v 1.4 2003/01/12 20:20:24 azurazu Exp $
//

include_once("../inc/config.inc.php");
$bgcolor = "#FCC229"; //Original style
//$bgcolor = "#B5C6D2"; //Greenish style
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
