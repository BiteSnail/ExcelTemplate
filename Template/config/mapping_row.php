<?php
    $field = isset($mapping['field']) ? $mapping['field'] : '';
    $meta = '';
    if (strpos($field, 'meta:') === 0) {
        $meta = substr($field, 5);
        $field = 'meta';
    }
    $placeholder = isset($mapping['placeholder']) && $mapping['placeholder'] !== '' ? '{{'.$mapping['placeholder'].'}}' : '';
?>
<tr class="excel-mapping-row">
    <td>
        <select name="mappings[field][]" class="excel-mapping-field">
            <option value=""><?= t('-- Choose a field --') ?></option>
            <optgroup label="<?= t('Task fields (repeated per task)') ?>">
                <?php foreach ($catalog as $key => $info): ?>
                    <?php if ($info[1] === 'item'): ?>
                        <option value="<?= $key ?>" <?= $field === $key ? 'selected' : '' ?>><?= $this->text->e($info[0]) ?></option>
                    <?php endif ?>
                <?php endforeach ?>
            </optgroup>
            <optgroup label="<?= t('Document fields (filled once)') ?>">
                <?php foreach ($catalog as $key => $info): ?>
                    <?php if ($info[1] === 'common'): ?>
                        <option value="<?= $key ?>" <?= $field === $key ? 'selected' : '' ?>><?= $this->text->e($info[0]) ?></option>
                    <?php endif ?>
                <?php endforeach ?>
            </optgroup>
        </select>
        <input type="text" name="mappings[meta][]" class="excel-mapping-meta" value="<?= $this->text->e($meta) ?>" placeholder="<?= t('Metadata key') ?>" <?= $field === 'meta' ? '' : 'style="display:none"' ?>>
    </td>
    <td class="excel-mapping-arrow"><i class="fa fa-long-arrow-right"></i></td>
    <td>
        <input type="text" name="mappings[placeholder][]" class="excel-mapping-placeholder" value="<?= $this->text->e($placeholder) ?>" placeholder="{{배포 항목}}">
    </td>
    <td>
        <button type="button" class="btn excel-mapping-remove js-excel-mapping-remove" title="<?= t('Remove') ?>">✕</button>
    </td>
</tr>
