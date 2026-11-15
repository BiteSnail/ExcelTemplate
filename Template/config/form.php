<?php
    $mappings = isset($values['mappings']) ? $values['mappings'] : array();
    $mapped = array();
    foreach ($mappings as $m) {
        $mapped[$m['placeholder']] = true;
    }
    $selectedProjects = isset($values['project_ids']) ? array_map('intval', (array) $values['project_ids']) : array();
    $pattern = isset($values['filename_pattern']) ? $values['filename_pattern'] : '';
    $unusedMapped = array();
    if ($template) {
        foreach ($mapped as $name => $_) {
            if (! in_array($name, $placeholders, true) && strpos($pattern, $name) === false) {
                $unusedMapped[] = $name;
            }
        }
    }
?>
<div class="page-header">
    <h2><?= $template ? t('Edit Excel template') : t('New Excel template') ?></h2>
    <ul>
        <li><?= $this->url->icon('list', t('All templates'), 'ExcelTemplateConfigController', 'index', array('plugin' => 'ExcelTemplate')) ?></li>
    </ul>
</div>

<form method="post" enctype="multipart/form-data" action="<?= $this->url->href('ExcelTemplateConfigController', 'save', array('plugin' => 'ExcelTemplate')) ?>" autocomplete="off" class="excel-template-form">
    <?= $this->form->csrf() ?>
    <?php if ($template): ?>
        <input type="hidden" name="id" value="<?= (int) $template['id'] ?>">
    <?php endif ?>

    <fieldset>
        <legend><?= t('Template') ?></legend>

        <?= $this->form->label(t('Name'), 'name') ?>
        <?= $this->form->text('name', $values, $errors, array('required', 'maxlength="191"', 'autofocus')) ?>

        <?= $this->form->label(t('Description'), 'description') ?>
        <?= $this->form->text('description', $values, $errors, array('maxlength="255"')) ?>

        <?= $this->form->label(t('Excel file (.xlsx)'), 'template_file') ?>
        <?php if ($template): ?>
            <p class="excel-template-current">
                <i class="fa fa-file-excel-o"></i>
                <?= $this->url->link($this->text->e($template['file_name']), 'ExcelTemplateConfigController', 'download', array('plugin' => 'ExcelTemplate', 'template_id' => $template['id'])) ?>
                <span class="excel-template-muted"><?= t('Upload a new file only to replace it.') ?></span>
            </p>
        <?php endif ?>
        <input type="file" name="template_file" id="form-template_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" <?= $template ? '' : 'required' ?>>
        <?php if (! empty($errors['template_file'])): ?>
            <ul class="form-errors"><?php foreach ($errors['template_file'] as $error): ?><li><?= $this->text->e($error) ?></li><?php endforeach ?></ul>
        <?php endif ?>

        <?php if ($template): ?>
            <div class="excel-template-found">
                <strong><?= t('Placeholders found in the file:') ?></strong>
                <?php if (empty($placeholders)): ?>
                    <span class="excel-template-muted"><?= t('None. Write {{name}} in the cells (or sheet names) to be filled.') ?></span>
                <?php endif ?>
                <?php foreach ($placeholders as $name): ?>
                    <?php if (isset($mapped[$name])): ?>
                        <span class="excel-template-chip excel-template-chip-ok" title="<?= t('Mapped') ?>">{{<?= $this->text->e($name) ?>}}</span>
                    <?php else: ?>
                        <button type="button" class="excel-template-chip excel-template-chip-missing js-excel-mapping-add-placeholder" data-placeholder="<?= $this->text->e($name) ?>" title="<?= t('Not mapped yet - click to add a mapping row') ?>">{{<?= $this->text->e($name) ?>}} +</button>
                    <?php endif ?>
                <?php endforeach ?>
                <?php if (! empty($unusedMapped)): ?>
                    <p class="excel-template-warning">
                        <i class="fa fa-exclamation-triangle"></i>
                        <?= t('Mapped but not found in the file or the file name:') ?>
                        <?php foreach ($unusedMapped as $name): ?><span class="excel-template-chip">{{<?= $this->text->e($name) ?>}}</span><?php endforeach ?>
                    </p>
                <?php endif ?>
            </div>
        <?php endif ?>
    </fieldset>

    <fieldset>
        <legend><?= t('Field mapping') ?></legend>
        <p class="form-help"><?= t('Key: a Kanboard task field. Value: the placeholder written in the Excel file, e.g. {{Release item}}. Placeholders that are not mapped are left as they are.') ?></p>

        <?php if (! empty($errors['mappings'])): ?>
            <ul class="form-errors"><?php foreach (array_unique($errors['mappings']) as $error): ?><li><?= $this->text->e($error) ?></li><?php endforeach ?></ul>
        <?php endif ?>

        <table class="excel-mapping-table">
            <thead>
                <tr>
                    <th><?= t('Kanboard field (key)') ?></th>
                    <th></th>
                    <th><?= t('Excel placeholder (value)') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="excel-mapping-rows">
                <?php foreach ($mappings as $mapping): ?>
                    <?= $this->render('ExcelTemplate:config/mapping_row', array('mapping' => $mapping, 'catalog' => $catalog)) ?>
                <?php endforeach ?>
                <?php if (empty($mappings)): ?>
                    <?= $this->render('ExcelTemplate:config/mapping_row', array('mapping' => array(), 'catalog' => $catalog)) ?>
                <?php endif ?>
            </tbody>
        </table>
        <template id="excel-mapping-row-template"><?= $this->render('ExcelTemplate:config/mapping_row', array('mapping' => array(), 'catalog' => $catalog)) ?></template>
        <button type="button" class="btn js-excel-mapping-add"><?= t('+ Add mapping') ?></button>
    </fieldset>

    <fieldset>
        <legend><?= t('Output') ?></legend>

        <?= $this->form->label(t('File name'), 'filename_pattern') ?>
        <?= $this->form->text('filename_pattern', $values, $errors, array('maxlength="255"', 'placeholder="배포계획서_{{배포일}}_{{제목}}"')) ?>
        <p class="form-help"><?= t('Mapped placeholders can be used in the file name. ".xlsx" is added automatically. In combined output, task placeholders take the values of the first selected task.') ?></p>

        <label><?= t('Combined output (all selected tasks in one file)') ?></label>
        <?php foreach ($modes as $mode => $label): ?>
            <label class="excel-template-radio">
                <input type="radio" name="combine_mode" value="<?= $mode ?>" <?= (isset($values['combine_mode']) ? $values['combine_mode'] : 'rows') === $mode ? 'checked' : '' ?>>
                <?= $this->text->e($label) ?>
            </label>
        <?php endforeach ?>
        <p class="form-help">
            <?= t('Repeat rows: the rows from the first to the last row that contain task placeholders are copied once per task, and everything below is pushed down (formulas, merged cells, print area and images follow).') ?><br>
            <?= t('Repeat sheets: every sheet that contains task placeholders is copied once per task. Put task placeholders in the sheet name to name each sheet, e.g. {{Title}}.') ?><br>
            <?= t('Tip: in a header like "Release date: ...", use a document field (e.g. Earliest due date) so the header row is not repeated.') ?>
        </p>

        <label><?= t('Available in projects') ?></label>
        <div class="excel-template-projects">
            <?php foreach ($projects as $projectId => $projectName): ?>
                <label><input type="checkbox" name="project_ids[]" value="<?= (int) $projectId ?>" <?= in_array((int) $projectId, $selectedProjects, true) ? 'checked' : '' ?>> <?= $this->text->e($projectName) ?></label>
            <?php endforeach ?>
        </div>
        <p class="form-help"><?= t('Leave everything unchecked to make the template available in all projects.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
        <?= t('or') ?> <?= $this->url->link(t('cancel'), 'ExcelTemplateConfigController', 'index', array('plugin' => 'ExcelTemplate')) ?>
    </div>
</form>
