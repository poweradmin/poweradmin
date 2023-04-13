<?php

function getLocaleFile(string $iface_lang): string
{
    if (in_array($iface_lang, ['cs_CZ', 'de_DE', 'fr_FR', 'ja_JP', 'nb_NO', 'nl_NL', 'pl_PL', 'ru_RU', 'tr_TR', 'zh_CN'])) {
        $short_locale = substr($iface_lang, 0, 2);
        return dirname(__DIR__, 2) . "/locale/$iface_lang/LC_MESSAGES/$short_locale.po";
    }
    return dirname(__DIR__, 2) . "/locale/en_EN/LC_MESSAGES/en.po";
}
