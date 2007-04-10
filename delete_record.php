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
// $Id: delete_record.php,v 1.5 2002/12/10 01:29:47 azurazu Exp $
//

require_once("inc/toolkit.inc.php");

if ($_GET["id"]) {
        if ($_GET["confirm"] == '0') {
                clean_page("edit.php?id=".$_GET["domain"]);
        } elseif ($_GET["confirm"] == '1') {
                delete_record($_GET["id"]);
                clean_page("edit.php?id=".$_GET["domain"]);
        }
        include_once("inc/header.inc.php");
        ?><H2>Delete record "<?
        $data = get_record_from_id($_GET["id"]);
        print $data["name"]." IN ".$data["type"]." ".$data["content"];
        ?>"</H2><?
        if (($data["type"] == "NS" && $data["name"] == get_domain_name_from_id($_GET["domain"])) || $data["type"] == "SOA") {
                print "<FONT CLASS=\"warning\">You are trying to delete a record that is needed for this zone to work.</FONT><BR>";
        }
        ?><BR><FONT CLASS="warning">Are you sure?</FONT><BR><BR>
        <INPUT TYPE="button" CLASS="button" OnClick="location.href='<?= $_SERVER["REQUEST_URI"] ?>&confirm=1'" VALUE="Yes"> <INPUT TYPE="button" CLASS="button" OnClick="location.href='<?= $_SERVER["REQUEST_URI"] ?>&confirm=0'" VALUE="No">
        <?
} else {
        include_once("inc/header.inc.php");
        die("Nothing to do!");
}
include_once("inc/footer.inc.php");
