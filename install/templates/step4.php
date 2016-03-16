<?php include __DIR__ . '/header.php'; ?>

    <?php include INSTALLER_DIRECTORY . 'templates/validation_errors.php'; ?>

    <form action="<?= $_SERVER['PHP_SELF']; ?>?step=4<?=defined('DEBUG') ? '&amp;debug=true' : '' ?>" method="POST" name="db_settings">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th colspan="2"><?=_('General Settings') ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="text-right"><label for="hostmaster"><?=_('E-Mail of Hostmaster') ?>:</label></td>
                <td><input type="text" class="form-control" name="hostmaster" value="<?= getValue('hostmaster') ?>" placeholder="hostmaster@example.com"></td>
            </tr>
            <tr>
                <td class="text-right"><label for="primaryNameserver"><?=_('Primary Nameserver') ?>:</label></td>
                <td><input type="text" class="form-control" name="primaryNameserver" value="<?= getValue('primaryNameserver') ?>" placeholder="ns1.example.com"></td>
            </tr>
            <tr>
                <td class="text-right"><label for="secondaryNameserver"><?=_('Secondary Nameserver') ?>:</label></td>
                <td><input type="text" class="form-control" name="secondaryNameserver" value="<?= getValue('secondaryNameserver') ?>" placeholder="ns2.example.com"></td>
            </tr>
            <tr>
                <td class="text-right"><label for="tertiaryNameserver"><?=_('Tertiary Nameserver') ?>:</label></td>
                <td><input type="text" class="form-control" name="tertiaryNameserver" value="<?= getValue('tertiaryNameserver') ?>" placeholder="ns3.example.com"></td>
            </tr>
            </tbody>
        </table>

        <table class="table table-bordered">
            <thead>
            <tr>
                <th colspan="2"><?=_('Administrative User') ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="text-right"><label for="fullname"><?=_('Full Name') ?>:</label></td>
                <td><input type="text" class="form-control" name="fullname" id="fullname" value="<?= getValue('fullname') ?>"></td>
            </tr>
            <tr>
                <td class="text-right"><label for="email"><?=_('E-Mail') ?>:</label></td>
                <td><input type="email" class="form-control" name="email" id="email" value="<?= getValue('email') ?>"></td>
            </tr>
            <tr>
                <td class="text-right"><label for="username"><?=_('Username') ?>:</label></td>
                <td><input type="text" class="form-control" name="username" id="username" value="<?= getValue('username') ?>"></td>
            </tr>
            <tr>
                <td class="text-right"><label for="password"><?=_('Password') ?>:</label></td>
                <td><input type="password" class="form-control" name="password" id="password" value="<?= getValue('password') ?>">
                </td>
            </tr>
            <tr>
                <td class="text-right"><label for="passwordRepeat"><?=_('Password Repeat') ?>:</label></td>
                <td><input type="password" class="form-control" name="passwordRepeat" id="passwordRepeat" value="<?= getValue('passwordRepeat') ?>"></td>
            </tr>
            </tbody>
        </table>

        <input type="submit" class="btn btn-primary pull-right" name="submit" value="<?=_('Start Installation') ?>">
    </form>

<?php include __DIR__ . '/footer.php'; ?>