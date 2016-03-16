<?php include __DIR__ . '/header.php'; ?>

    <h2><?= _('Welcome to the installation of Poweradmin!') ?></h2>

    <div class="alert alert-info text-justify">
        <?= _('We are pleased that you want to install our software Poweradmin. Please read carefully thorough the following information to complete the setup successfully.') ?>
        <?= _('The installation-script will check the required PHP modules & settings within the next steps. Also the supported database-engines will be tested.') ?>
        <?= _('Poweradmin requires to have a working instance of PowerDNS with connection to database-server and the default sql-schema installed.') ?>
        <br>
        <br>

        <p class="text-center">
            <strong>
                <?= _('While installing Poweradmin this script will NOT touch the existing data inside the PowerDNS tables.') ?>
                <?= _('A backup before installing Poweradmin will be still recommended!') ?>
            </strong>
        </p>
    </div>

    <div class="alert alert-warning text-center">
        <strong>
            <?= _('This script is ONLY for installing Poweradmin! If you are running a previous version of Poweradmin please read through the Upgrade-Guide.') ?>
            <br>
            <?= _('All tables (including data) provided by Poweradmin will be deleted by running this setup-script!') ?>
        </strong>
    </div>

    <form action="<?= $_SERVER['PHP_SELF'] ?>?step=1<?=(defined('DEBUG') ? '&amp;debug=true' : '') ?>" method="POST">
        <label<?= (isset($errors) && array_key_exists('confirmInformation', $errors) ? ' style="color: red;"' : '') ?>>
            <input type="checkbox" name="confirmInformation" value="yes"> <?= _('I promise I have read the informations carefully and know what I am doing!') ?>
        </label>
        <input type="submit" class="btn btn-primary pull-right" name="submit" value="<?= _('Continue') ?>">
    </form>

<?php include __DIR__ . '/footer.php'; ?>