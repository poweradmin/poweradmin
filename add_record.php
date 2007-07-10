<?php

require_once("inc/toolkit.inc.php");

$xsid = (isset($_GET['id'])) ? $_GET['id'] : $_POST['zoneid'];
if ((!level(5)) && ((!xs($xsid) || ($_SESSION[$xsid.'_ispartial'])))) {
	error(ERR_RECORD_ACCESS_DENIED);
}

if ($_POST["commit"]) {
        $ret = add_record($_POST["zoneid"], $_POST["name"], $_POST["type"], $_POST["content"], $_POST["ttl"], $_POST["prio"]);
        if ($ret != '1') {
                die("$ret");
        }
        clean_page("edit.php?id=".$_POST["zoneid"]);
}

include_once("inc/header.inc.php");
?>

    <h2><? echo _('Add record to zone'); ?> "<? echo get_domain_name_from_id($_GET["id"]) ?>"</H2>

    <form method="post">
     <input type="hidden" name="zoneid" value="<? echo $_GET["id"] ?>">
     <table border="0" cellspacing="4">
      <tr>
       <td class="n"><? echo _('Name'); ?></td>
       <td class="n">&nbsp;</td>
       <td class="n"><? echo _('Type'); ?></td>
       <td class="n"><? echo _('Priority'); ?></td>
       <td class="n"><? echo _('Content'); ?></td>
       <td class="n"><? echo _('TTL'); ?></td>
      </tr>
      <tr>
       <td class="n"><input type="text" name="name" class="input">.<? echo get_domain_name_from_id($_GET["id"]) ?></td>
       <td class="n">IN</td>
       <td class="n">
        <select name="type">
<?
$dname = get_domain_name_from_id($_GET["id"]);
foreach (get_record_types() as $c) {
        if (eregi('in-addr.arpa', $dname) && strtoupper($c) == 'PTR') {
                $add = " SELECTED";
        } elseif (strtoupper($c) == 'A') {
                $add = " SELECTED";
        } else {
                unset($add);
        }
        ?><option<? echo $add ?> value="<? echo $c ?>"><? echo $c ?></option><?
}
?>
        </select>
       </td>
       <td class="n"><input type="text" name="prio" class="sinput"></td>
       <td class="n"><input type="text" name="content" class="input"></td>
       <td class="n"><input type="text" name="ttl" class="sinput" value="<? echo $DEFAULT_TTL?>"></td>
      </tr>
     </table>
     <br>
     <input type="submit" name="commit" value="<? echo _('Add record'); ?>" class="button">
    </form>

<? include_once("inc/footer.inc.php"); ?>
