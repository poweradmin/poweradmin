<?php include __DIR__ . '/header.php'; ?>

    <div class="alert alert-info">
        <strong><?=_('Please Note:') ?></strong>
        <?=_('We have changed the install procedure. There is no need to provide a superuser at any-time. The user specified here will be written to config-file.') ?>
    </div>

    <?php include INSTALLER_DIRECTORY . 'templates/validation_errors.php'; ?>

    <form action="<?= $_SERVER['PHP_SELF']; ?>?step=3<?=defined('DEBUG') ? '&debug=true' : '' ?>" method="POST" name="db_settings">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th colspan="2"><?=_('Database Credentials') ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="col-md-4 text-right"><label for="dbDriver"><?=_('Driver') ?>:</label></td>
                <td>
                    <select name="dbDriver" id="dbDriver">
                        <?php foreach ($config['availableSupportedDatabaseDrivers'] as $i => $driver): ?>
                            <option value="<?= $driver ?>"<?=getValue('dbDriver', 'pdo_mysql') === $driver ? ' selected' : '' ?>><?= $config['supportedDatabaseDrivers'][$driver] ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr class="sqlite">
                <td class="text-right"><label for="dbFile"><?=_('Database-File') ?>:</label></td>
                <td><input type="text" class="form-control" name="dbFile" id="dbFile" value="<?= getValue('dbFile', $_SERVER['DOCUMENT_ROOT']) ?>"></td>
            </tr>
            <tr class="mysql_pgsql">
                <td class="text-right"><label for="dbHost"><?=_('Host') ?>:</label></td>
                <td><input type="text" class="form-control" name="dbHost" id="dbHost" value="<?= getValue('dbHost', '127.0.0.1') ?>"></td>
            </tr>
            <tr class="mysql_pgsql">
                <td class="text-right"><label for="dbPort"><?=_('Port') ?>:</label></td>
                <td><input type="text" class="form-control" name="dbPort" id="dbPort" value="<?= getValue('dbPort', getDbPortDefault()) ?>"></td>
            </tr>
            <tr>
                <td class="text-right"><label for="dbUsername"><?=_('Username') ?>:</label></td>
                <td><input type="text" class="form-control" name="dbUsername" id="dbUsername" value="<?= getValue('dbUsername') ?>"></td>
            </tr>
            <tr>
                <td class="text-right"><label for="dbPassword"><?=_('Password') ?>:</label></td>
                <td><input type="password" class="form-control" name="dbPassword" id="dbPassword" value="<?= getValue('dbPassword') ?>"></td>
            </tr>
            <tr class="mysql_pgsql">
                <td class="text-right"><label for="dbDatabase"><?=_('Database') ?>:</label></td>
                <td><input type="text" class="form-control" name="dbDatabase" id="dbDatabase" value="<?= getValue('dbDatabase', 'poweradmin') ?>"></td>
            </tr>
            <tr class="mysql_pgsql">
                <td class="text-right"><label for="dbCharset"><?=_('Charset') ?>:</label></td>
                <td>
                    <select name="dbCharset" id="dbCharset">
                        <option value="latin1"<?=getValue('dbCharset') === 'latin1' ? ' selected' : '' ?>>ISO 8859-1</option>
                        <option value="utf8"<?=getValue('dbCharset', 'utf8') === 'utf8' ? ' selected' : '' ?>>UTF-8</option>
                    </select>
                </td>
            </tr>
            </tbody>
        </table>

        <?php if (isset($connectionStatus, $requiredTables) && $connectionStatus && count($requiredTables) === 0): ?>
            <div class="alert alert-success"><?=_('Connection to database-server was successful!') ?></div>
            <a class="btn btn-primary pull-right" href="<?= $_SERVER['PHP_SELF'] ?>?step=4<?=defined('DEBUG') ? '&amp;debug=true' : '' ?>"><?=_('Continue') ?></a>
        <?php else: ?>
            <?php if (isset($connectionStatus) && !$connectionStatus): ?>
                <div class="alert alert-danger"><?=_('Could not connect to database-server with the given informations!') ?></div>
            <?php endif; ?>

            <?php if (isset($requiredTables) && count($requiredTables) !== 0): ?>
                <div class="alert alert-danger"><?=sprintf(_('Required tables "%s" from PowerDNS-Schema missing!'), implode(', ', $requiredTables)) ?></div>
            <?php endif; ?>

            <input type="submit" class="btn btn-primary pull-right" name="submit" value="<?=_('Test Data') ?>">
        <?php endif; ?>
    </form>

<?php include __DIR__ . '/footer.php'; ?>