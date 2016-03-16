<?php include __DIR__ . '/header.php'; ?>

    <h2><?=_('Installation finished') ?></h2>

    <?php if (count($permissions) === $permissionsCreated): ?>
        <div class="alert alert-success">Successfully imported default system-permissions.</div>
    <?php else: ?>
        <div class="alert alert-danger">Error while importing default system-permissions.</div>
        <?php $error = true; ?>
    <?php endif; ?>

    <?php if ($permissionTemplateResult->rowCount() === 1 && $permissionTemplateItemResult->rowCount() === 1): ?>
        <div class="alert alert-success">Successfully created default permission-template.</div>
    <?php else: ?>
        <div class="alert alert-danger">Error while create default permission-template.</div>
        <?php $error = true; ?>
    <?php endif; ?>

    <?php if ($userResult->rowCount() === 1): ?>
        <div class="alert alert-success">Successfully created user "<?=$user['username'] ?>".</div>
    <?php else: ?>
        <div class="alert alert-danger">Error while creating user "<?=$user['username'] ?>".</div>
        <?php $error = true; ?>
    <?php endif; ?>

    <?php if($configWriteResult !== false): ?>
        <div class="alert alert-success">Successfully created config-file.</div>
    <?php else: ?>
        <div class="alert alert-danger">Error while creating config-file.</div>
        <?php $error = true; ?>
    <?php endif; ?>

    <?php if (!isset($error) || (isset($error) && $error === false)): ?>
        <div class="alert alert-info">
            <?=_('You have successfully installed Poweradmin! Please delete the install-directory and login to use Poweradmin.') ?><br>
            <em><?=_('If you want to use Poweradmin for DynDNS, please see instructions in our wiki!') ?></em>
        </div>

        <a href="/" class="btn btn-primary pull-right">Go to Poweradmin</a>
    <?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>