<?php

namespace Kanboard\Plugin\ExcelTemplate\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\PageNotFoundException;

/**
 * 설정 > 엑셀 양식 (관리자)
 *
 * @property \Kanboard\Plugin\ExcelTemplate\Model\ExcelTemplateModel $excelTemplateModel
 */
class ExcelTemplateConfigController extends BaseController
{
    public function index()
    {
        $this->response->html($this->helper->layout->config('ExcelTemplate:config/index', array(
            'title' => t('Settings').' &gt; '.t('Excel templates'),
            'templates' => $this->excelTemplateModel->getAll(),
            'modes' => $this->excelTemplateModel->getModes(),
            'projects' => $this->projectModel->getList(false, false),
        )));
    }

    public function create(array $values = array(), array $errors = array())
    {
        $this->renderForm(null, $values ?: array(
            'combine_mode' => 'rows',
            'mappings' => array(),
            'project_ids' => array(),
        ), $errors);
    }

    public function edit(array $values = array(), array $errors = array())
    {
        $template = $this->getTemplate();
        $this->renderForm($template, $values ?: $template, $errors);
    }

    private function renderForm($template, array $values, array $errors)
    {
        $placeholders = $template ? $this->excelTemplateModel->scanPlaceholders($template) : array();

        $this->response->html($this->helper->layout->config('ExcelTemplate:config/form', array(
            'title' => t('Settings').' &gt; '.t('Excel templates'),
            'template' => $template,
            'values' => $values,
            'errors' => $errors,
            'catalog' => $this->excelTemplateModel->getFieldCatalog(),
            'modes' => $this->excelTemplateModel->getModes(),
            'projects' => $this->projectModel->getList(false, false),
            'placeholders' => $placeholders,
        )));
    }

    public function save()
    {
        $values = $this->request->getValues();

        if (empty($values)) {
            throw new \Kanboard\Core\Controller\AccessForbiddenException();
        }

        $id = isset($values['id']) ? (int) $values['id'] : 0;
        $template = $id ? $this->getTemplate($id) : null;
        $file = $this->request->getFileInfo('template_file');
        $mappings = $this->excelTemplateModel->parseMappings($values);
        $errors = $this->excelTemplateModel->validate($values, $mappings, $template === null, $file);

        if (! empty($errors)) {
            $values['mappings'] = $mappings;
            $values['project_ids'] = isset($values['project_ids']) ? array_map('intval', (array) $values['project_ids']) : array();
            $this->flash->failure(t('Unable to save the template.'));
            $this->renderForm($template, $values, $errors);
            return;
        }

        try {
            $savedId = $this->excelTemplateModel->save($values, $mappings, $file, $id);
        } catch (\RuntimeException $e) {
            $values['mappings'] = $mappings;
            $values['project_ids'] = isset($values['project_ids']) ? array_map('intval', (array) $values['project_ids']) : array();
            $this->flash->failure(t('Unable to save the template.'));
            $this->renderForm($template, $values, array('template_file' => array($e->getMessage())));
            return;
        }

        if ($savedId) {
            $this->flash->success(t('Template saved successfully.'));
            $this->response->redirect($this->helper->url->to('ExcelTemplateConfigController', 'edit', array('plugin' => 'ExcelTemplate', 'template_id' => $savedId)));
        } else {
            $this->flash->failure(t('Unable to save the template.'));
            $this->renderForm($template, $values, $errors);
        }
    }

    public function confirm()
    {
        $template = $this->getTemplate();

        $this->response->html($this->template->render('ExcelTemplate:config/remove', array(
            'template' => $template,
        )));
    }

    public function remove()
    {
        $this->checkCSRFParam();
        $template = $this->getTemplate();

        if ($this->excelTemplateModel->remove($template['id'])) {
            $this->flash->success(t('Template removed successfully.'));
        } else {
            $this->flash->failure(t('Unable to remove this template.'));
        }

        $this->response->redirect($this->helper->url->to('ExcelTemplateConfigController', 'index', array('plugin' => 'ExcelTemplate')), true);
    }

    public function download()
    {
        $template = $this->getTemplate();
        $name = $template['file_name'] ?: 'template.xlsx';

        $this->response->withFileDownload('template.xlsx');
        $this->response->withHeader('Content-Disposition', ExcelExportController::contentDisposition($name));
        $this->response->binary($this->excelTemplateModel->getFileContent($template));
    }

    private function getTemplate($id = 0)
    {
        $template = $this->excelTemplateModel->getById($id ?: $this->request->getIntegerParam('template_id'));

        if (! $template) {
            throw new PageNotFoundException();
        }

        return $template;
    }
}
