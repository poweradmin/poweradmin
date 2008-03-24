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

/* Edit all fields below here to your information */

/* MySQL Configuration */

//
// Host we should connect to.
// This could be for example "localhost" or a sock file
$dbhost = 'localhost';

//
// Your user with SELECT/INSERT/UPDATE/DELETE/CREATE access to $dbdatabase
$dbuser = '';

//
// Your password, the password for $dbuser
$dbpass = '';

// Your database, the database you want to use for PowerDNS (or are already using)
$dbdatabase   = '';

// The dsn you want to use (which database you want to use)
// Tested is mysql and pgsql default is mysql
$dbdsntype = 'mysql';

/* URI Configuration */

// $BASE_URL
// This will be the main URI you will use to connect to PowerAdmin.
// For instance: "http://poweradmin.sjeemz.nl"
$BASE_URL = "http://";

// $BASE_PATH
// If PowerAdmin is in a subdir. Specify this here
// For instance: "/admin/"
$BASE_PATH = "/admin/";

// $LANG
// Which language should be used for the web interface?
$LANG = "en_EN";

// $STYLE
// Define skin of web frontend. This should be the basename of the CSS file that
// will be included, it will be used like: "style/$STYLE.css.php".
$STYLE = "example";

// $ROWAMOUNT
// Define which rowamount should be used in the listing of records.
$ROWAMOUNT = 50;

/* DNS Record information */


// $HOSTMASTER
// The email address of the hostmaster you want to be mailed.
// For instance: "hostmaster@sjeemz.nl"
$HOSTMASTER = "";

// $NS1
// Your first nameserver
// Should be a domainname! Not an IP.
$NS1 = "";

// $NS2
// Your second nameserver.
// If you dont have a second nameserver, fill in the same value as $NS1
$NS2 = "";

/* You dont have to edit these fields. Change them if you want. */


// $EXPIRE
// Session timeout in seconds. This is 1800 seconds which is 30 minutes by default.
// The information in this field should be in seconds.
// After this $EXPIRE you are automatically logged out from the system.
$EXPIRE = 1800;

// $DEFAULT_TTL
// Default TTL for records.
// Default time to live for all records. This notation is in seconds.
$DEFAULT_TTL = 86400;      // (3600 seconds / 1 hour by default)

// Enable fancy records or not (http://doc.powerdns.com/fancy-records.html)? true/false
$FANCY_RECORDS = true;


/* ------------------------------------------ */
/* No need to make changes below this line... */
/* Which means, dont touch it		      */
/* ------------------------------------------ */

/* -------------------------------------------------------------------- */
/* NO REALLY DONT TOUCH IT! Unless you _REALLY_ know what you are doing */
/* -------------------------------------------------------------------- */

// $rtypes - array of possible record types
$rtypes = array('A', 'AAAA', 'CNAME', 'HINFO', 'MX', 'NAPTR', 'NS', 'PTR', 'SOA', 'TXT');

// If fancy records is enabled, extend this field.
if($FANCY_RECORDS)
{
	$rtypes[10] = 'URL';
	$rtypes[11] = 'MBOXFW';
}

// $template - array of records that will be applied when adding a new zone file
$template = array(
                array(

                                "name"          =>              "##DOMAIN##",
                                "type"          =>              "SOA",
                                "content"       =>              "$NS1 $HOSTMASTER 1",
                                "ttl"           =>              "$DEFAULT_TTL",
                                "prio"          =>              ""
                ),
                array(
                                "name"          =>              "##DOMAIN##",
                                "type"          =>              "NS",
                                "content"       =>              "$NS1",
                                "ttl"           =>              "$DEFAULT_TTL",
                                "prio"          =>              ""
                ),
                array(
                                "name"          =>              "##DOMAIN##",
                                "type"          =>              "NS",
                                "content"       =>              "$NS2",
                                "ttl"           =>              "$DEFAULT_TTL",
                                "prio"          =>              ""
                ),
                array(
                                "name"          =>              "www.##DOMAIN##",
                                "type"          =>              "A",
                                "content"       =>              "##WEBIP##",
                                "ttl"           =>              "$DEFAULT_TTL",
                                "prio"          =>              ""
                ),
                array(
                                "name"          =>              "##DOMAIN##",
                                "type"          =>              "A",
                                "content"       =>              "##WEBIP##",
                                "ttl"           =>              "$DEFAULT_TTL",
                                "prio"          =>              ""
                ),
                array(
                                "name"          =>              "mail.##DOMAIN##",
                                "type"          =>              "A",
                                "content"       =>              "##MAILIP##",
                                "ttl"           =>              "$DEFAULT_TTL",
                                "prio"          =>              ""
                ),
                array(
                                "name"          =>              "localhost.##DOMAIN##",
                                "type"          =>              "A",
                                "content"       =>              "127.0.0.1",
                                "ttl"           =>              "$DEFAULT_TTL",
                                "prio"          =>              ""
                ),
                array(
                                "name"          =>              "##DOMAIN##",
                                "type"          =>              "MX",
                                "content"       =>              "mail.##DOMAIN##",
                                "ttl"           =>              "$DEFAULT_TTL",
                                "prio"          =>              "10"
                )
);
?>
