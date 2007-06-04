<?
include_once("inc/config.inc.php");

$language = $LANG;
setlocale(LC_ALL, $language);
$gettext_domain = 'messages';
bindtextdomain($gettext_domain, "./locale");
textdomain($gettext_domain);

?>
