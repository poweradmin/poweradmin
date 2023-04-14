<?php

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\PoFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

function initializeTwigEnvironment($language) {
    $loader = new FilesystemLoader('templates');
    $twig = new Environment($loader);

    $translator = new Translator($language);
    $translator->addLoader('po', new PoFileLoader());
    $translator->addResource('po', getLocaleFile($language), $language);

    $twig->addExtension(new TranslationExtension($translator));

    return $twig;
}

function getCurrentStep(): int
{
    if (isset($_POST['step']) && is_numeric($_POST['step'])) {
        return $_POST['step'];
    }

    return 1;
}

function renderHeader($twig, $current_step): void
{
    echo $twig->render('header.html', array(
        'current_step' => htmlspecialchars($current_step),
        'file_version' => time()
    ));
}

function renderFooter($twig): void
{
    echo $twig->render('footer.html');
}
