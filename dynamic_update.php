<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2011  Poweradmin Development Team <http://www.poweradmin.org/credits>
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

/*
Necessary includes
*/
require('inc/config.inc.php');
require('inc/database.inc.php');
$db = dbConnect();

/*
Make query safe
*/
function safe($value) {
        $value = mysql_real_escape_string($value);
        return $value;
}

/*
Exit status
*/
function status_exit($status) {
        $verbose_codes = array(
                'badagent'=>'Your user agent is not valid.',
                'badauth'=>'Invalid username or password.  Authentication failed.',
                'notfqdn'=>'The hostname you specified was not valid.',
                'dnserr'=>'A DNS error has occurred on our end.  We apologize for any inconvenience.',
                '!yours'=>'The specified hostname does not belong to you.',
                'nohost'=>'The specified hostname does not exist.',
                'good'=>'Your hostname has been updated.',
                '911'=>'A critical error has occurred on our end.  We apologize for any inconvenience.',
                'nochg'=>'This update was identical to your last update, so no changes were made to your hostname configuration.'
        );
        if ($_REQUEST['verbose']) {
                list($code,$msg) = explode(' ',$status,2);
                $status = $verbose_codes[$code] . ($msg?' '.$msg:'');
        }
        echo "$status\n";
        return false;
}

if (!$_SERVER['HTTP_USER_AGENT']) return status_exit('badagent');
if (!isset($_SERVER['PHP_AUTH_USER'])) {
        header('WWW-Authenticate: Basic realm="DNS Update"');
        header('HTTP/1.0 401 Unauthorized');
        return status_exit('badauth');
 }

$username = safe($_SERVER['PHP_AUTH_USER']);
$password = md5(safe($_SERVER['PHP_AUTH_PW']));
$hostname = safe($_REQUEST['hostname']);
$ip = safe($_REQUEST['myip']);
if (!preg_match('/^((?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/i',$ip)) {
        return status_exit('dnserr');
}

if (!strlen($hostname)) return status_exit('notfqdn');
/*
Don't allow super user to be used to update
*/
if ($username == 'admin') return status_exit('badauth');

$query = mysql_query("SELECT * FROM users WHERE username='$username' and password='$password'");
$queryusers = $db->queryOne($query);

//$userdetails = mysql_fetch_array($queryusers, MYSQL_ASSOC);
$userdetails = $queryusers->fetchRow();

$querydomains = "SELECT domain_id FROM zones WHERE owner='{$userdetails["id"]}'";
$querydomains = $db->query($querydomains);
$domainunauth = $querydomains->numRows();

while ($row = $querydomains->fetchRow) {

        $hostname = "SELECT name FROM records WHERE domain_id='{$row["domain_id"]}' and type = 'A'";
	$queryhostname = $hostname->query();
        while ($row2 = $queryhostname->fetchRow) {
                if($hostname == $row2['name']){
                $updatequery ="UPDATE records SET content ='{$ip}' where domain_id='{$row["domain_id"]}' and type='A'";
		$query = $db->query($updatequery);
                $domainunauth = "-1";
                }
        }
}

if($domainunauth<0) return status_exit('good');

return status_exit('!yours');

?>
