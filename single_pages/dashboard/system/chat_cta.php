<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>
<?php
$token = Core::make('token');
$form = Core::make('helper/form');
$settings = $settings ?? [];
$numbers = $numbers ?? [];
$buttonColor = $settings['button_color'] ?? '#25D366';
?>

<div class="ccm-dashboard-header-buttons">
    <button type="button" class="btn btn-secondary" id="chat-cta-add-row">
        <i class="fas fa-plus" aria-hidden="true"></i> <?= t('Add Number') ?>
    </button>
</div>

<form method="post" action="<?= $view->action('') ?>" id="chat-cta-settings-form">
    <?php $token->output('save_chat_cta'); ?>

    <?php if (empty($numbers)): ?>
        <div class="alert alert-info">
            <?= t('Add at least one active contact number. The global button will only render when the package is enabled and at least one contact is active.') ?>
        </div>
    <?php endif; ?>

    <h2><?= t('Contact Numbers') ?></h2>
    <p class="text-muted">
        <?= t('One active number is selected randomly whenever a visitor clicks the global chat button. Weight controls how often a number is selected relative to the others.') ?>
    </p>

    <div class="table-responsive mb-4">
        <table class="table table-striped align-middle" id="chat-cta-table">
            <thead>
                <tr>
                    <th><?= t('Label') ?></th>
                    <th><?= t('Phone') ?></th>
                    <th style="width: 110px;" class="text-end"><?= t('Weight') ?></th>
                    <th style="width: 110px;" class="text-end"><?= t('Sort') ?></th>
                    <th style="width: 100px;" class="text-center"><?= t('Enabled') ?></th>
                    <th style="width: 90px;" class="text-center"><?= t('Delete') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($numbers as $i => $number): ?>
                <tr>
                    <td>
                        <?= $form->hidden('id[]', (int) $number['id']) ?>
                        <?= $form->text('label[]', $number['label'] ?? '', [
                            'placeholder' => t('Reception'),
                            'class' => 'form-control',
                        ]) ?>
                    </td>
                    <td>
                        <?= $form->text('phone[]', $number['phone'] ?? '', [
                            'placeholder' => '+255712345678',
                            'class' => 'form-control',
                        ]) ?>
                    </td>
                    <td>
                        <?= $form->number('weight[]', (int) ($number['weight'] ?? 1), [
                            'min' => 1,
                            'class' => 'form-control text-end',
                        ]) ?>
                    </td>
                    <td>
                        <?= $form->number('sortOrder[]', (int) ($number['sortOrder'] ?? 0), [
                            'class' => 'form-control text-end',
                        ]) ?>
                    </td>
                    <td class="text-center">
                        <div class="form-check d-inline-block m-0">
                            <?= $form->checkbox('isActive[' . $i . ']', 1, !empty($number['isActive']), [
                                'class' => 'form-check-input',
                                'aria-label' => t('Enabled'),
                            ]) ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <div class="form-check d-inline-block m-0">
                            <?= $form->checkbox('delete[]', (string) $number['id'], false, [
                                'class' => 'form-check-input',
                                'aria-label' => t('Delete'),
                            ]) ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="container-fluid">
        <div class="row">
            <div class="col col-md-12">
                <h2><?= t('Settings') ?></h2>
                <div class="mb-3">
                    <div class="form-check">
                        <?= $form->checkbox('enabled', 1, !empty($settings['enabled']), [
                            'id' => 'enabled',
                            'class' => 'form-check-input',
                        ]) ?>
                        <?= $form->label('enabled', t('Enable Chat CTA on public pages'), ['class' => 'form-check-label']) ?>
                    </div>
                    <div class="form-text">
                        <?= t('When enabled, the button is injected automatically on public-facing pages. It is never shown in the Dashboard and does not require a block.') ?>
                    </div>
                </div>


                <div class="mb-3">
                    <?= $form->label('button_label', t('Button label'), ['class' => 'form-label']) ?>
                    <?= $form->text('button_label', $settings['button_label'] ?? 'Chat with us', [
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="mb-3">
                    <?= $form->label('default_message', t('Prefilled message'), ['class' => 'form-label']) ?>
                    <?= $form->textarea('default_message', $settings['default_message'] ?? '', [
                        'rows' => 4,
                        'class' => 'form-control',
                    ]) ?>
                </div>
            </div>
            <div class="col md-6">
                <div class="mb-3">
                    <?= $form->label('position', t('Button position'), ['class' => 'form-label']) ?>
                    <?= $form->select('position', [
                        'bottom-right' => t('Bottom right'),
                        'bottom-left' => t('Bottom left'),
                    ], $settings['position'] ?? 'bottom-right', [
                        'class' => 'form-select',
                    ]) ?>
                </div>
            </div>
            <div class="col col-md-6">
                <div class="mb-4">
                    <?= $form->label('button_color', t('Button color'), ['class' => 'form-label']) ?>
                    <div class="input-group">
                        <?= $form->text('button_color', $buttonColor, [
                            'placeholder' => '#25D366',
                            'class' => 'form-control',
                        ]) ?>
                        <span class="input-group-text" style="background: <?= h($buttonColor) ?>; min-width: 42px;"></span>
                    </div>
                    <div class="form-text">
                        <?= t('Use a hex color value, for example #25D366.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="ccm-dashboard-form-actions-wrapper">
    <div class="ccm-dashboard-form-actions">
        <button type="submit" form="chat-cta-settings-form" class="btn btn-primary float-end">
            <?= t('Save Settings') ?>
        </button>
    </div>
</div>

<template id="chat-cta-row-template">
    <tr>
        <td>
            <input type="hidden" name="id[]" value="0">
            <input type="text" name="label[]" value="" class="form-control" placeholder="<?= h(t('Reception')) ?>">
        </td>
        <td><input type="text" name="phone[]" value="" class="form-control" placeholder="+1555345678"></td>
        <td><input type="number" name="weight[]" value="1" min="1" class="form-control text-end"></td>
        <td><input type="number" name="sortOrder[]" value="0" class="form-control text-end"></td>
        <td class="text-center">
            <div class="form-check d-inline-block m-0">
                <input type="checkbox" name="isActive[__INDEX__]" value="1" class="form-check-input" aria-label="<?= h(t('Enabled')) ?>" checked>
            </div>
        </td>
        <td class="text-center"><span class="text-muted">—</span></td>
    </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var button = document.getElementById('chat-cta-add-row');
    var tbody = document.querySelector('#chat-cta-table tbody');
    var template = document.getElementById('chat-cta-row-template');
    var nextIndex = <?= count($numbers) ?>;
    if (button && tbody && template) {
        button.addEventListener('click', function () {
            var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
            var wrapper = document.createElement('tbody');
            wrapper.innerHTML = html;
            tbody.appendChild(wrapper.firstElementChild);
        });
    }
});
</script>
