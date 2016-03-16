</section>

<footer class="row">
    <div class="col-xs-12 col-md-6">(c) 2007-<?=date('Y') ?> | <a href="https://github.com/poweradmin/poweradmin/graphs/contributors" target="_blank">Poweradmin-Team</a></div>
    <div class="clearfix visible-xs-block"></div>
    <div class="col-xs-12 col-md-6 text-right">
        <?php if (function_exists('gettext')): ?>
            <form name="change_locale" action="<?= $_SERVER['REQUEST_URI']; ?>" method="POST">
                <select name="locale">
                    <?php foreach ($config['locales'] as $value => $localeName): ?>
                        <option
                            value="<?= $value ?>"<?= ($parameters['locale'] === $value ? ' selected' : '') ?>><?= $localeName ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>
</footer>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js" integrity="sha384-0mSbJDEHialfmuBBQP6A4Qrprq5OVfW37PRR3j5ELqxss1yVqOtnepnHVP9aJ7xS" crossorigin="anonymous"></script>
<script src="assets/install.js"></script>

<?php

if (file_exists(INSTALLER_DIRECTORY . 'templates/step' . $parameters['step'] . '_js.php')) {
    include INSTALLER_DIRECTORY . 'templates/step' . $parameters['step'] . '_js.php';
}

?>

</body>
</html>