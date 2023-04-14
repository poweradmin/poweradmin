<?php

function getLocaleFile(string $iface_lang): string
{
    if (in_array($iface_lang, ['cs_CZ', 'de_DE', 'fr_FR', 'ja_JP', 'nb_NO', 'nl_NL', 'pl_PL', 'ru_RU', 'tr_TR', 'zh_CN'])) {
        $short_locale = substr($iface_lang, 0, 2);
        return dirname(__DIR__, 2) . "/locale/$iface_lang/LC_MESSAGES/$short_locale.po";
    }
    return dirname(__DIR__, 2) . "/locale/en_EN/LC_MESSAGES/en.po";
}

function getLanguageFromRequest(): string
{
    $defaultLanguage = 'en_EN';

    if (isset($_POST['language']) && $_POST['language'] != $defaultLanguage) {
        return $_POST['language'];
    }

    return $defaultLanguage;
}

function setLanguage($language): void
{
    if ($language != 'en_EN') {
        $locale = setlocale(LC_ALL, $language, $language . '.UTF-8');
        if (!$locale) {
            error(_('Failed to set locale. Selected locale may be unsupported on this system. Please contact your administrator.'));
        }

        $gettext_domain = 'messages';
        bindtextdomain($gettext_domain, "./../locale");
        textdomain($gettext_domain);
        @putenv('LANG=' . $language);
        @putenv('LANGUAGE=' . $language);
    }
}