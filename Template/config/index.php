<div class="page-header">
    <h2><?= t('Excel templates') ?></h2>
    <ul>
        <li><?= $this->url->icon('plus', t('New template'), 'ExcelTemplateConfigController', 'create', array('plugin' => 'ExcelTemplate')) ?></li>
    </ul>
</div>

<p class="alert alert-info">
    <?= t('Register an .xlsx form and map Kanboard fields to the {{placeholders}} written in it. Members can then export selected tasks from the project list view (Apply action menu) or from a task page.') ?>
</p>

<?php if (empty($templates)): ?>
    <p class="alert"><?= t('No Excel template registered yet.') ?></p>
<?php else: ?>
    <table class="table-striped table-scrolling">
        <tr>
            <th class="column-20"><?= t('Name') ?></th>
            <th><?= t('Mappings') ?></th>
            <th class="column-15"><?= t('Combined output') ?></th>
            <th class="column-15"><?= t('Projects') ?></th>
            <th class="column-15"><?= t('File') ?></th>
            <th class="column-10"><?= t('Action') ?></th>
        </tr>
        <?php foreach ($templates as $template): ?>
        <tr>
            <td>
                <strong><?= $this->url->link($this->text->e($template['name']), 'ExcelTemplateConfigController', 'edit', array('plugin' => 'ExcelTemplate', 'template_id' => $template['id'])) ?></strong>
                <?php if ($template['description'] !== ''): ?><div class="excel-template-muted"><?= $this->text->e($template['description']) ?></div><?php endif ?>
            </td>
            <td>
                <?php foreach ($template['mappings'] as $mapping): ?>
                    <span class="excel-template-chip">{{<?= $this->text->e($mapping['placeholder']) ?>}}</span>
                <?php endforeach ?>
                <?php if (empty($template['mappings'])): ?><span class="excel-template-muted"><?= t('None') ?></span><?php endif ?>
            </td>
            <td><?= $this->text->e($modes[$template['combine_mode']]) ?></td>
            <td>
                <?php if (empty($template['project_ids'])): ?>
                    <?= t('All projects') ?>
                <?php else: ?>
                    <?= $this->text->e(implode(', ', array_map(function ($id) use ($projects) { return isset($projects[$id]) ? $projects[$id] : '#'.$id; }, $template['project_ids']))) ?>
                <?php endif ?>
            </td>
            <td><?= $this->url->link($this->text->e($template['file_name']), 'ExcelTemplateConfigController', 'download', array('plugin' => 'ExcelTemplate', 'template_id' => $template['id'])) ?></td>
            <td>
                <div class="dropdown">
                    <a href="#" class="dropdown-menu dropdown-menu-link-icon"><i class="fa fa-cog"></i><i class="fa fa-caret-down"></i></a>
                    <ul>
                        <li><?= $this->url->icon('edit', t('Edit'), 'ExcelTemplateConfigController', 'edit', array('plugin' => 'ExcelTemplate', 'template_id' => $template['id'])) ?></li>
                        <li><?= $this->url->icon('download', t('Download template file'), 'ExcelTemplateConfigController', 'download', array('plugin' => 'ExcelTemplate', 'template_id' => $template['id'])) ?></li>
                        <li><?= $this->modal->confirm('trash-o', t('Remove'), 'ExcelTemplateConfigController', 'confirm', array('plugin' => 'ExcelTemplate', 'template_id' => $template['id'])) ?></li>
                    </ul>
                </div>
            </td>
        </tr>
        <?php endforeach ?>
    </table>
<?php endif ?>
