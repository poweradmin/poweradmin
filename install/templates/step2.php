<?php include __DIR__ . '/header.php'; ?>

    <h3><?= _('PHP-Requirements') ?></h3>
    <table class="table table-bordered">
        <thead>
        <tr>
            <th></th>
            <th><?= _('Required Value') ?></th>
            <th><?= _('Your Value') ?></th>
        </tr>
        </thead>
        <tbody>
        <tr<?= ($phpRequirements['php-version'] ? ' class="success"' : ' class="danger"') ?>>
            <td><?= _('PHP-Version'); ?></td>
            <td>>= 5.5</td>
            <td><?= PHP_VERSION; ?></td>
        </tr>
        <tr<?= ($phpRequirements['php-module-session'] ? ' class="success"' : ' class="danger"') ?>>
            <td><?= _('PHP-Extension'); ?> "session"</td>
            <td><?= _('installed'); ?></td>
            <td><?= ($phpRequirements['php-module-session'] ? _('installed') : _('not installed')) ?></td>
        </tr>
        <tr<?= ($phpRequirements['php-module-gettext'] ? ' class="success"' : ' class="danger"') ?>>
            <td><?= _('PHP-Extension'); ?> "gettext"</td>
            <td><?= _('installed'); ?></td>
            <td><?= ($phpRequirements['php-module-gettext'] ? _('installed') : _('not installed')) ?></td>
        </tr>
        <tr<?= ($phpRequirements['php-module-mcrypt'] ? ' class="success"' : ' class="danger"') ?>>
            <td><?= _('PHP-Extension'); ?> "mcrypt"</td>
            <td><?= _('installed'); ?></td>
            <td><?= ($phpRequirements['php-module-mcrypt'] ? _('installed') : _('not installed')) ?></td>
        </tr>
        <tr<?= ($phpRequirements['php-function-exec'] ? ' class="success"' : ' class="danger"') ?>>
            <td><?= _('PHP-Function'); ?> "exec"</td>
            <td><?= _('available'); ?></td>
            <td><?= ($phpRequirements['php-function-exec'] ? _('available') : _('disabled')) ?></td>
        </tr>
        </tbody>
    </table>

    <h3><?= _('Supported Database-Driver'); ?></h3>
    <table class="table table-bordered">
        <thead>
        <tr>
            <th><?= _('Driver'); ?></th>
            <th><?= _('Status'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($config['supportedDatabaseDrivers'] as $driver => $name): ?>
            <tr<?= (in_array($driver, $config['availableSupportedDatabaseDrivers']) ? ' class="success"' : '') ?>>
                <td><?= $name ?></td>
                <td><?= (in_array($driver, $config['availableSupportedDatabaseDrivers']) ? _('available') : _('not available')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php if ($configFileIsWritable && !in_array(false, $phpRequirements) && count($config['availableSupportedDatabaseDrivers']) > 0): ?>
    <?php $_SESSION['step'] = 3; ?>
    <a href="<?= $_SERVER['PHP_SELF']; ?>?step=3<?=(defined('DEBUG') ? '&amp;debug=true' : '') ?>" class="btn btn-primary pull-right"><?= _('Continue'); ?></a>
<?php elseif (!$createConfigFile || !$configFileIsWritable): ?>
    <div
        class="alert alert-danger"><?= sprintf(_('Could not create Config-File "%s" or it is not writable!'), basename($config['config_file'])); ?></div>
<?php else: ?>
    <div
        class="alert alert-danger"><?= _('Your System does not fulfill the PHP requirements or there is no database-driver available!'); ?></div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>