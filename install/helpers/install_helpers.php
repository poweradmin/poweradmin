<?php

function checkConfigFile($current_step, $local_config_file, $twig): void
{
    if ($current_step == 1 && file_exists($local_config_file)) {
        echo "<p class='alert alert-danger'>" . _('There is already a configuration file in place, so the installation will be skipped.') . "</p>";
        echo $twig->render('footer.html');
        exit;
    }
}
