<div class="page-header">
    <h2><?= t('Export to Excel template') ?></h2>
</div>

<?php if (empty($tasks)): ?>
    <p class="alert alert-error"><?= t('No task selected.') ?></p>
<?php elseif (empty($templates)): ?>
    <p class="alert"><?= t('No Excel template is available for this project.') ?></p>
    <?php if ($this->user->isAdmin()): ?>
        <p><?= $this->url->link(t('Register an Excel template'), 'ExcelTemplateConfigController', 'create', array('plugin' => 'ExcelTemplate')) ?></p>
    <?php endif ?>
<?php else: ?>
    <form class="js-modal-ignore-form excel-export-form" method="post" action="<?= $this->url->href('ExcelExportController', 'export', array('plugin' => 'ExcelTemplate', 'project_id' => $project['id'])) ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->text->e($csrf_token) ?>">
        <input type="hidden" name="task_ids" value="<?= implode(',', array_column($tasks, 'id')) ?>">

        <div class="excel-export-tasks">
            <strong><?= t('%d selected task(s)', count($tasks)) ?></strong>
            <ul>
                <?php foreach (array_slice($tasks, 0, 8) as $task): ?>
                    <li>#<?= (int) $task['id'] ?> <?= $this->text->e($task['title']) ?></li>
                <?php endforeach ?>
                <?php if (count($tasks) > 8): ?>
                    <li class="excel-template-muted"><?= t('and %d more', count($tasks) - 8) ?></li>
                <?php endif ?>
            </ul>
        </div>

        <label><?= t('Template') ?></label>
        <div class="excel-export-templates">
            <?php foreach ($templates as $i => $template): ?>
                <label class="excel-export-template">
                    <input type="radio" name="template_id" value="<?= (int) $template['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                    <span>
                        <strong><?= $this->text->e($template['name']) ?></strong>
                        <?php if ($template['description'] !== ''): ?><span class="excel-template-muted"> - <?= $this->text->e($template['description']) ?></span><?php endif ?>
                        <br><span class="excel-template-muted"><?= t('Combined') ?>: <?= $this->text->e($modes[$template['combine_mode']]) ?></span>
                    </span>
                </label>
            <?php endforeach ?>
        </div>

        <label><?= t('Output') ?></label>
        <label class="excel-template-radio">
            <input type="radio" name="output" value="combined" checked>
            <?= t('Combined: all selected tasks in one file') ?>
        </label>
        <label class="excel-template-radio">
            <input type="radio" name="output" value="each">
            <?= t('Each: one file per task (downloaded as a .zip when several tasks are selected)') ?>
        </label>

        <div class="form-actions">
            <button type="button" class="btn btn-blue js-excel-template-export"><?= t('Download') ?></button>
            <?= t('or') ?>
            <a href="#" class="js-modal-close"><?= t('cancel') ?></a>
        </div>
    </form>
<?php endif ?>
