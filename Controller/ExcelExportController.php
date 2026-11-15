<?php

namespace Kanboard\Plugin\ExcelTemplate\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ExcelTemplate\Model\ExcelExportModel;

/**
 * 목록 탭 / 태스크 화면에서 선택한 태스크를 엑셀 양식으로 내보내기
 *
 * @property \Kanboard\Plugin\ExcelTemplate\Model\ExcelTemplateModel $excelTemplateModel
 * @property \Kanboard\Plugin\ExcelTemplate\Model\ExcelExportModel   $excelExportModel
 */
class ExcelExportController extends BaseController
{
    /**
     * 모달: 양식 / 출력 방식 선택
     */
    public function show()
    {
        $project = $this->getProject();
        $taskIds = $this->parseTaskIds($this->request->getStringParam('task_ids'));
        $tasks = $this->excelExportModel->getTasks($taskIds, $project['id']);
        $templates = $this->excelTemplateModel->getForProject($project['id']);

        $this->response->html($this->template->render('ExcelTemplate:export/show', array(
            'project' => $project,
            'tasks' => $tasks,
            'templates' => $templates,
            'modes' => $this->excelTemplateModel->getModes(),
            'csrf_token' => $this->token->getReusableCSRFToken(),
        )));
    }

    /**
     * 파일 생성 후 다운로드
     */
    public function export()
    {
        $this->checkReusableCSRFParam();

        $project = $this->getProject();
        $templateId = (int) $this->request->getRawValue('template_id');
        $output = $this->request->getRawValue('output') === ExcelExportModel::OUTPUT_EACH ? ExcelExportModel::OUTPUT_EACH : ExcelExportModel::OUTPUT_COMBINED;
        $tasks = $this->excelExportModel->getTasks($this->parseTaskIds((string) $this->request->getRawValue('task_ids')), $project['id']);

        $template = null;
        foreach ($this->excelTemplateModel->getForProject($project['id']) as $candidate) {
            if ((int) $candidate['id'] === $templateId) {
                $template = $candidate;
            }
        }

        if ($template === null || empty($tasks)) {
            throw new AccessForbiddenException(t('Choose a template and at least one task.'));
        }

        $result = $this->excelExportModel->export($template, $tasks, $output, $project, $this->getUser());

        $this->response->withFileDownload($result['type'] === 'zip' ? 'export.zip' : 'export.xlsx');
        $this->response->withHeader('Content-Disposition', self::contentDisposition($result['filename']));
        $this->response->withHeader('Content-Length', strlen($result['content']));
        $this->response->binary($result['content']);
    }

    private function parseTaskIds($value)
    {
        return array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $value))));
    }

    /**
     * 한글 파일 이름이 깨지지 않도록 RFC 5987 형식 사용
     */
    public static function contentDisposition($filename)
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        $fallback = trim($fallback, '_') ?: 'download';

        return 'attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }
}
