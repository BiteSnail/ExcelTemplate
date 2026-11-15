<?php

namespace Kanboard\Plugin\ExcelTemplate\Library;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * xlsx 양식에 {{치환값}}을 채우는 엔진 (외부 라이브러리 없이 ZipArchive + DOM)
 *
 * - 셀 텍스트 / 시트 이름의 {{이름}} 치환
 * - rows   모드: 항목 치환값이 들어있는 행 묶음을 항목 수만큼 반복 (아래 내용은 밀려 내려감)
 * - sheets 모드: 항목 치환값이 들어있는 시트를 항목 수만큼 복제
 *
 * 행을 밀어낼 때 수식, 병합 셀, 조건부 서식, 데이터 유효성, 하이퍼링크, 표, 그림 위치,
 * 인쇄 영역(이름 정의), 다른 시트에서의 참조를 함께 보정합니다.
 */
class XlsxTemplate
{
    const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const NS_PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';
    const NS_CT = 'http://schemas.openxmlformats.org/package/2006/content-types';
    const NS_XDR = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';

    const REL_BASE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    const CT_WORKSHEET = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';

    const PLACEHOLDER = '/\{\{\s*(.+?)\s*\}\}/u';

    private $zip;
    private $readOnly;
    private $workbookPath;
    private $workbookRelsPath;
    private $workbook;
    private $workbookRels;
    private $contentTypes;
    private $shared = array();

    /** @var array 시트 목록 (워크북 순서) */
    private $sheets = array();

    /** @var DOMDocument[] 수정된 기타 XML 파트 */
    private $docs = array();

    /** @var string[] 새로 추가된 파트 */
    private $newFiles = array();

    /** @var array 이름만 예약된 새 파트 경로 */
    private $reserved = array();

    private $maxTableId = null;
    private $tableNames = null;

    /**
     * 템플릿 안의 {{치환값}} 이름 목록 (셀 + 시트 이름)
     */
    public static function scan($path)
    {
        $tpl = new self($path);
        $names = array();

        foreach ($tpl->sheets as $sheet) {
            foreach (self::placeholdersIn($sheet['name']) as $name) {
                $names[$name] = true;
            }

            if ($sheet['doc'] === null) {
                continue;
            }

            foreach ($tpl->cells($sheet['doc']) as $c) {
                $text = $tpl->cellText($c);
                if ($text !== null) {
                    foreach (self::placeholdersIn($text) as $name) {
                        $names[$name] = true;
                    }
                }
            }
        }

        $tpl->close();

        return array_keys($names);
    }

    /**
     * xlsx 파일로 열 수 있는지 확인
     */
    public static function isValid($path)
    {
        try {
            $tpl = new self($path);
            $ok = count($tpl->sheets) > 0;
            $tpl->close();
            return $ok;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function placeholdersIn($text)
    {
        if ($text === null || strpos($text, '{{') === false) {
            return array();
        }

        preg_match_all(self::PLACEHOLDER, $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @param string      $templatePath 양식 파일
     * @param string|null $outputPath   결과 파일 (null 이면 읽기 전용)
     */
    public function __construct($templatePath, $outputPath = null)
    {
        $this->readOnly = $outputPath === null;
        $path = $templatePath;

        if (! $this->readOnly) {
            if (! copy($templatePath, $outputPath)) {
                throw new RuntimeException('Unable to copy template');
            }
            $path = $outputPath;
        }

        $this->zip = new ZipArchive();

        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('Invalid xlsx file');
        }

        $this->load();
    }

    public function close()
    {
        if ($this->zip) {
            $this->zip->close();
            $this->zip = null;
        }
    }

    // ------------------------------------------------------------------
    // 렌더링
    // ------------------------------------------------------------------

    /**
     * @param array  $common   문서 공통 값 [이름 => 값]
     * @param array  $items    항목별 값 [[이름 => 값], ...]
     * @param array  $itemKeys 항목 치환값 이름 목록 (반복 기준)
     * @param string $mode     rows | sheets
     */
    public function render(array $common, array $items, array $itemKeys, $mode = 'rows')
    {
        if ($this->readOnly) {
            throw new RuntimeException('Template opened read-only');
        }

        $items = array_values($items);
        if (empty($items)) {
            $items = array(array());
        }

        $itemKeySet = array_flip($itemKeys);
        $first = array_merge($common, $items[0]);
        $originals = $this->sheets;
        $final = array();

        foreach ($originals as $index => $sheet) {
            if ($sheet['doc'] === null) {
                $sheet['new_name'] = $this->fillText($sheet['name'], $first);
                $final[] = $sheet;
                continue;
            }

            $itemRows = $this->findItemRows($sheet['doc'], $itemKeySet);
            $nameHasItem = count(array_intersect(self::placeholdersIn($sheet['name']), $itemKeys)) > 0;

            if ($mode === 'sheets' && (! empty($itemRows) || $nameHasItem)) {
                $xml = $sheet['doc']->saveXML();
                $previousNode = $sheet['node'];

                foreach ($items as $k => $item) {
                    $values = array_merge($common, $item);
                    $target = $k === 0 ? $sheet : $this->cloneSheet($sheet, $xml, $k, $previousNode);
                    $previousNode = $target['node'];
                    $this->fillDocument($target['doc'], $values);
                    $target['new_name'] = $this->fillText($sheet['name'], $values);

                    if ($target['new_name'] === $sheet['name'] && $k > 0) {
                        $target['new_name'] = $sheet['name'].' ('.($k + 1).')';
                    }

                    $target['orig_index'] = $index;
                    $target['clone_k'] = $k;
                    $final[] = $target;
                }
            } else {
                if (! empty($itemRows)) {
                    $this->repeatRows($sheet, $items, $common, min($itemRows), max($itemRows));
                } else {
                    $this->fillDocument($sheet['doc'], $first);
                }

                $sheet['new_name'] = $this->fillText($sheet['name'], $first);
                $sheet['orig_index'] = $index;
                $sheet['clone_k'] = 0;
                $final[] = $sheet;
            }
        }

        $this->finalizeSheets($originals, $final);
        $this->save();
    }

    private function findItemRows(DOMDocument $doc, array $itemKeySet)
    {
        $rows = array();

        foreach ($this->cells($doc) as $c) {
            $text = $this->cellText($c);
            if ($text === null) {
                continue;
            }

            foreach (self::placeholdersIn($text) as $name) {
                if (isset($itemKeySet[$name])) {
                    list(, $row) = FormulaRef::parseCell($c->getAttribute('r'));
                    $rows[$row] = $row;
                    break;
                }
            }
        }

        return array_values($rows);
    }

    // ------------------------------------------------------------------
    // 행 반복
    // ------------------------------------------------------------------

    private function repeatRows(array $sheet, array $items, array $common, $min, $max)
    {
        $doc = $sheet['doc'];
        $xp = $this->xpath($doc);
        $name = $sheet['name'];
        $h = $max - $min + 1;
        $n = count($items);
        $delta = ($n - 1) * $h;

        $this->expandSharedFormulas($doc);

        $isSelf = function ($sheetName) use ($name) {
            return $sheetName === null || self::sameName($sheetName, $name);
        };

        $outside = function ($sheetName, array $ref) use ($isSelf, $min, $max, $delta) {
            return $isSelf($sheetName) ? self::shiftOutside($ref, $min, $max, $delta) : null;
        };

        $copyRule = function ($k) use ($isSelf, $min, $max, $h, $delta) {
            return function ($sheetName, array $ref) use ($isSelf, $min, $max, $h, $delta, $k) {
                if (! $isSelf($sheetName)) {
                    return null;
                }

                if (! empty($ref['range']) && $ref['ra1'] && $ref['ra2']) {
                    return self::shiftOutside($ref, $min, $max, $delta);
                }

                foreach (array('1', '2') as $e) {
                    if ($e === '2' && empty($ref['range'])) {
                        break;
                    }
                    $r = $ref['r'.$e];
                    if ($r >= $min && $r <= $max) {
                        if (! $ref['ra'.$e]) {
                            $ref['r'.$e] = $r + $k * $h;
                        }
                    } elseif ($r > $max) {
                        $ref['r'.$e] = $r + $delta;
                    }
                }

                return $ref;
            };
        };

        $sheetData = $xp->query('//m:sheetData')->item(0);
        $above = array();
        $block = array();
        $below = array();

        foreach ($xp->query('m:row', $sheetData) as $row) {
            $r = (int) $row->getAttribute('r');
            if ($r < $min) {
                $above[] = $row;
            } elseif ($r <= $max) {
                $block[] = $row;
            } else {
                $below[] = $row;
            }
        }

        $anchor = empty($below) ? null : $below[0];
        $rowValues = array();

        // 1) 반복 묶음 복제 (원본 상태 기준)
        for ($k = 1; $k < $n; $k++) {
            foreach ($block as $row) {
                $clone = $row->cloneNode(true);
                $this->setRowNumber($clone, (int) $row->getAttribute('r') + $k * $h);
                $this->mapRowFormulas($clone, $copyRule($k));

                if ($anchor === null) {
                    $sheetData->appendChild($clone);
                } else {
                    $sheetData->insertBefore($clone, $anchor);
                }

                $rowValues[] = array($clone, array_merge($common, $items[$k]));
            }
        }

        // 2) 원본 묶음 (k = 0)
        foreach ($block as $row) {
            $this->mapRowFormulas($row, $copyRule(0));
            $rowValues[] = array($row, array_merge($common, $items[0]));
        }

        // 3) 위 / 아래 행
        foreach ($above as $row) {
            $this->mapRowFormulas($row, $outside);
            $rowValues[] = array($row, array_merge($common, $items[0]));
        }

        foreach ($below as $row) {
            $this->mapRowFormulas($row, $outside);
            $this->setRowNumber($row, (int) $row->getAttribute('r') + $delta);
            $rowValues[] = array($row, array_merge($common, $items[0]));
        }

        foreach ($rowValues as $pair) {
            foreach ($xp->query('m:c', $pair[0]) as $c) {
                $this->fillCell($c, $pair[1]);
            }
        }

        if ($delta === 0) {
            return;
        }

        // 4) 병합 셀
        foreach ($xp->query('//m:mergeCells/m:mergeCell') as $merge) {
            $range = self::parseRange($merge->getAttribute('ref'));
            if ($range === null) {
                continue;
            }

            if ($range[1] >= $min && $range[3] <= $max) {
                for ($k = 1; $k < $n; $k++) {
                    $clone = $merge->cloneNode(true);
                    $clone->setAttribute('ref', self::rangeString($range[0], $range[1] + $k * $h, $range[2], $range[3] + $k * $h));
                    $merge->parentNode->appendChild($clone);
                }
            } else {
                $merge->setAttribute('ref', self::rangeString($range[0], self::shiftRow($range[1], $max, $delta), $range[2], self::shiftEnd($range[1], $range[3], $min, $max, $delta)));
            }
        }

        $mergeCells = $xp->query('//m:mergeCells')->item(0);
        if ($mergeCells) {
            $mergeCells->setAttribute('count', $xp->query('m:mergeCell', $mergeCells)->length);
        }

        // 5) 조건부 서식 / 데이터 유효성 / 시트 자동 필터
        foreach ($xp->query('//m:conditionalFormatting | //m:dataValidations/m:dataValidation') as $node) {
            $node->setAttribute('sqref', self::shiftSqref($node->getAttribute('sqref'), $min, $max, $delta));
            foreach ($xp->query('.//m:formula | .//m:formula1 | .//m:formula2', $node) as $f) {
                $f->textContent = self::xmlText(FormulaRef::map($f->textContent, $outside));
            }
        }

        foreach ($xp->query('//m:autoFilter') as $node) {
            $node->setAttribute('ref', self::shiftSqref($node->getAttribute('ref'), $min, $max, $delta));
        }

        // 6) 하이퍼링크
        foreach ($xp->query('//m:hyperlinks/m:hyperlink') as $link) {
            $range = self::parseRange($link->getAttribute('ref'));
            if ($range === null) {
                continue;
            }
            if ($range[1] >= $min && $range[3] <= $max) {
                for ($k = 1; $k < $n; $k++) {
                    $clone = $link->cloneNode(true);
                    $clone->setAttribute('ref', self::rangeString($range[0], $range[1] + $k * $h, $range[2], $range[3] + $k * $h, true));
                    $link->parentNode->appendChild($clone);
                }
            } elseif ($range[1] > $max) {
                $link->setAttribute('ref', self::rangeString($range[0], $range[1] + $delta, $range[2], $range[3] + $delta, true));
            }
        }

        // 7) 사용 범위 / 페이지 나누기
        $dimension = $xp->query('//m:dimension')->item(0);
        if ($dimension) {
            $range = self::parseRange($dimension->getAttribute('ref'));
            if ($range !== null && $range[3] >= $min) {
                $dimension->setAttribute('ref', self::rangeString($range[0], $range[1], $range[2], $range[3] + $delta));
            }
        }

        foreach ($xp->query('//m:rowBreaks/m:brk') as $brk) {
            $id = (int) $brk->getAttribute('id');
            if ($id > $max) {
                $brk->setAttribute('id', $id + $delta);
            }
        }

        // 8) 시트에 연결된 표 / 그림 / 메모
        foreach ($this->sheetRelationships($sheet['path']) as $rel) {
            if ($rel['type'] === 'table') {
                $tdoc = $this->part($rel['target']);
                if ($tdoc) {
                    $txp = $this->xpath($tdoc);
                    foreach ($txp->query('/m:table | /m:table/m:autoFilter') as $node) {
                        $node->setAttribute('ref', self::shiftSqref($node->getAttribute('ref'), $min, $max, $delta));
                    }
                }
            } elseif ($rel['type'] === 'drawing') {
                $ddoc = $this->part($rel['target']);
                if ($ddoc) {
                    $dxp = new DOMXPath($ddoc);
                    $dxp->registerNamespace('xdr', self::NS_XDR);
                    foreach ($dxp->query('//xdr:from/xdr:row | //xdr:to/xdr:row') as $node) {
                        $row0 = (int) $node->textContent;
                        if ($row0 + 1 > $max) {
                            $node->textContent = $row0 + $delta;
                        }
                    }
                }
            } elseif ($rel['type'] === 'comments') {
                $cdoc = $this->part($rel['target']);
                if ($cdoc) {
                    foreach ($this->xpath($cdoc)->query('//m:comment') as $node) {
                        list($col, $row) = FormulaRef::parseCell($node->getAttribute('ref'));
                        if ($row > $max) {
                            $node->setAttribute('ref', FormulaRef::cell($col, $row + $delta));
                        }
                    }
                }
            } elseif ($rel['type'] === 'vmlDrawing') {
                $vml = $this->readPart($rel['target']);
                if ($vml !== false && $vml !== null) {
                    $vml = preg_replace_callback('/<x:Row>(\d+)<\/x:Row>/', function ($m) use ($max, $delta) {
                        return '<x:Row>'.((int) $m[1] + 1 > $max ? (int) $m[1] + $delta : (int) $m[1]).'</x:Row>';
                    }, $vml);
                    $vml = preg_replace_callback('/<x:Anchor>([^<]*)<\/x:Anchor>/', function ($m) use ($max, $delta) {
                        $v = array_map('trim', explode(',', $m[1]));
                        if (count($v) === 8) {
                            foreach (array(2, 6) as $i) {
                                if ((int) $v[$i] + 1 > $max) {
                                    $v[$i] = (int) $v[$i] + $delta;
                                }
                            }
                        }
                        return '<x:Anchor>'.implode(', ', $v).'</x:Anchor>';
                    }, $vml);
                    $this->newFiles[$rel['target']] = $vml;
                }
            }
        }

        // 9) 다른 시트 수식 / 이름 정의(인쇄 영역 등)에서 이 시트를 가리키는 참조
        $external = function ($sheetName, array $ref) use ($name, $min, $max, $delta) {
            return ($sheetName !== null && self::sameName($sheetName, $name)) ? self::shiftOutside($ref, $min, $max, $delta) : null;
        };

        foreach ($this->sheets as $other) {
            if ($other['doc'] === null || $other['doc'] === $doc) {
                continue;
            }
            foreach ($this->xpath($other['doc'])->query('//m:c/m:f') as $f) {
                if ($f->textContent !== '') {
                    $f->textContent = self::xmlText(FormulaRef::map($f->textContent, $external));
                }
            }
        }

        foreach ($this->xpath($this->workbook)->query('//m:definedNames/m:definedName') as $dn) {
            $dn->textContent = self::xmlText(FormulaRef::map($dn->textContent, $external));
        }
    }

    private function mapRowFormulas(DOMElement $row, callable $callback)
    {
        foreach ($row->getElementsByTagNameNS(self::NS_MAIN, 'f') as $f) {
            if ($f->textContent !== '') {
                $f->textContent = self::xmlText(FormulaRef::map($f->textContent, $callback));
            }

            if ($f->hasAttribute('ref')) {
                $range = self::parseRange($f->getAttribute('ref'));
                if ($range !== null) {
                    $ref = array('c1' => $range[0], 'r1' => $range[1], 'ca1' => false, 'ra1' => false, 'range' => true, 'c2' => $range[2], 'r2' => $range[3], 'ca2' => false, 'ra2' => false);
                    $new = $callback(null, $ref);
                    if ($new !== null) {
                        $f->setAttribute('ref', self::rangeString($new['c1'], $new['r1'], $new['c2'], $new['r2'], true));
                    }
                }
            }
        }
    }

    private function setRowNumber(DOMElement $row, $number)
    {
        $row->setAttribute('r', $number);

        foreach ($row->getElementsByTagNameNS(self::NS_MAIN, 'c') as $c) {
            list($col) = FormulaRef::parseCell($c->getAttribute('r'));
            $c->setAttribute('r', FormulaRef::cell($col, $number));
        }
    }

    /**
     * 공유 수식(t="shared")을 일반 수식으로 풀어서 행 복제 시 깨지지 않게 함
     */
    private function expandSharedFormulas(DOMDocument $doc)
    {
        $xp = $this->xpath($doc);
        $masters = array();
        $nodes = array();

        foreach ($xp->query('//m:c/m:f[@t="shared"]') as $f) {
            $nodes[] = $f;
            if ($f->textContent !== '' && $f->hasAttribute('si')) {
                list($col, $row) = FormulaRef::parseCell($f->parentNode->getAttribute('r'));
                $masters[$f->getAttribute('si')] = array($f->textContent, $col, $row);
            }
        }

        foreach ($nodes as $f) {
            $si = $f->getAttribute('si');

            if ($f->textContent === '' && isset($masters[$si])) {
                list($col, $row) = FormulaRef::parseCell($f->parentNode->getAttribute('r'));
                $f->textContent = self::xmlText(FormulaRef::translate($masters[$si][0], $row - $masters[$si][2], $col - $masters[$si][1]));
            }

            $f->removeAttribute('t');
            $f->removeAttribute('ref');
            $f->removeAttribute('si');
        }
    }

    // ------------------------------------------------------------------
    // 시트 복제
    // ------------------------------------------------------------------

    private function cloneSheet(array $orig, $xml, $k, DOMElement $after)
    {
        $dir = dirname($orig['path']);
        $path = $this->uniquePartName($orig['path']);

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($xml);

        foreach ($this->xpath($doc)->query('//m:sheetView[@tabSelected]') as $view) {
            $view->removeAttribute('tabSelected');
        }

        // 시트 관계 파일 복사 (그림, 메모, 표, 인쇄 설정은 시트마다 별도 파트가 필요)
        $relsPath = self::relsPathFor($orig['path']);
        $relsXml = $this->readPart($relsPath);

        if ($relsXml) {
            $rels = new DOMDocument();
            $rels->loadXML($relsXml);

            foreach ($rels->getElementsByTagNameNS(self::NS_PKG, 'Relationship') as $rel) {
                if ($rel->getAttribute('TargetMode') === 'External') {
                    continue;
                }

                $type = self::relType($rel->getAttribute('Type'));
                if (! in_array($type, array('drawing', 'vmlDrawing', 'comments', 'table', 'printerSettings', 'threadedComment'), true)) {
                    continue;
                }

                $target = self::resolvePath($dir, $rel->getAttribute('Target'));
                $content = $this->readPart($target);
                if ($content === false || $content === null) {
                    continue;
                }

                $newTarget = $this->uniquePartName($target);

                if ($type === 'table') {
                    $content = $this->renameTable($content, $k);
                }

                $this->newFiles[$newTarget] = $content;
                $this->copyContentType($target, $newTarget);

                $subRels = $this->readPart(self::relsPathFor($target));
                if ($subRels) {
                    $this->newFiles[self::relsPathFor($newTarget)] = $subRels;
                }

                $rel->setAttribute('Target', self::relativePath(dirname($path), $newTarget));
            }

            $this->newFiles[self::relsPathFor($path)] = $rels->saveXML();
        }

        // 워크북에 시트 등록
        $rid = $this->nextRelId($this->workbookRels);
        $relNode = $this->workbookRels->createElementNS(self::NS_PKG, 'Relationship');
        $relNode->setAttribute('Id', $rid);
        $relNode->setAttribute('Type', self::REL_BASE.'worksheet');
        $relNode->setAttribute('Target', self::relativePath(dirname($this->workbookPath), $path));
        $this->workbookRels->documentElement->appendChild($relNode);

        $node = $this->workbook->createElementNS(self::NS_MAIN, 'sheet');
        foreach ($orig['node']->attributes as $attr) {
            if (! in_array($attr->localName, array('name', 'sheetId', 'id'), true)) {
                $node->setAttribute($attr->nodeName, $attr->nodeValue);
            }
        }
        $node->setAttribute('name', 'tmp'.uniqid());
        $node->setAttribute('sheetId', $this->nextSheetId());
        $node->setAttributeNS(self::NS_R, 'r:id', $rid);

        if ($after->nextSibling) {
            $after->parentNode->insertBefore($node, $after->nextSibling);
        } else {
            $after->parentNode->appendChild($node);
        }

        $this->addOverride('/'.$path, self::CT_WORKSHEET);

        $clone = array(
            'name' => $orig['name'],
            'path' => $path,
            'doc' => $doc,
            'node' => $node,
        );

        $this->sheets[] = $clone;

        return $clone;
    }

    private function renameTable($xml, $k)
    {
        $this->loadTableInfo();

        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $table = $doc->documentElement;
        $table->setAttribute('id', ++$this->maxTableId);

        $base = preg_replace('/[^A-Za-z0-9_\x{80}-\x{10FFFF}]/u', '_', $table->getAttribute('name') ?: 'Table');
        $name = $base.'_'.($k + 1);
        $i = 2;
        while (isset($this->tableNames[mb_strtolower($name)])) {
            $name = $base.'_'.($k + 1).'_'.$i++;
        }
        $this->tableNames[mb_strtolower($name)] = true;

        $table->setAttribute('name', $name);
        $table->setAttribute('displayName', $name);

        return $doc->saveXML();
    }

    private function loadTableInfo()
    {
        if ($this->maxTableId !== null) {
            return;
        }

        $this->maxTableId = 0;
        $this->tableNames = array();

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (preg_match('#^xl/tables/[^/]+\.xml$#', $name)) {
                $doc = new DOMDocument();
                if (@$doc->loadXML($this->zip->getFromIndex($i))) {
                    $this->maxTableId = max($this->maxTableId, (int) $doc->documentElement->getAttribute('id'));
                    $this->tableNames[mb_strtolower($doc->documentElement->getAttribute('name'))] = true;
                }
            }
        }
    }

    /**
     * 시트 이름 확정, 이름 정의 재배치, 시트 이름 변경 반영
     */
    private function finalizeSheets(array $originals, array $final)
    {
        // 최종 이름 (엑셀 규칙: 31자, 금지 문자, 중복 불가)
        $used = array();
        foreach ($final as $i => $sheet) {
            $name = self::sanitizeSheetName($sheet['new_name']);
            $base = $name;
            $n = 2;
            while (isset($used[mb_strtolower($name)]) || mb_strtolower($name) === 'history') {
                $suffix = ' ('.$n++.')';
                $name = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
            }
            $used[mb_strtolower($name)] = true;
            $final[$i]['final_name'] = $name;
        }

        // 워크북 순서 기준 새 인덱스
        $order = array();
        foreach ($this->xpath($this->workbook)->query('//m:sheets/m:sheet') as $pos => $node) {
            $order[spl_object_id($node)] = $pos;
        }

        $clonesByOrig = array();
        $finalOfOrig = array();
        foreach ($final as $sheet) {
            $sheet['node']->setAttribute('name', $sheet['final_name']);
            if (isset($sheet['clone_k']) && $sheet['clone_k'] > 0) {
                $clonesByOrig[$sheet['orig_index']][] = $sheet;
            } elseif (isset($sheet['orig_index'])) {
                $finalOfOrig[$sheet['orig_index']] = $sheet;
            }
        }

        // 시트별 이름 정의(인쇄 영역 등) 재배치 + 복제본용 이름 정의 추가
        $xp = $this->xpath($this->workbook);
        foreach ($xp->query('//m:definedNames/m:definedName[@localSheetId]') as $dn) {
            $oldIndex = (int) $dn->getAttribute('localSheetId');
            if (! isset($originals[$oldIndex])) {
                continue;
            }

            $dn->setAttribute('localSheetId', $order[spl_object_id($originals[$oldIndex]['node'])]);

            if (! empty($clonesByOrig[$oldIndex])) {
                foreach ($clonesByOrig[$oldIndex] as $clone) {
                    $copy = $dn->cloneNode(true);
                    $copy->setAttribute('localSheetId', $order[spl_object_id($clone['node'])]);
                    $copy->textContent = self::xmlText(FormulaRef::renameSheet($dn->textContent, $originals[$oldIndex]['name'], $clone['final_name']));
                    $dn->parentNode->appendChild($copy);
                }
            }
        }

        // 복제 시트 안에서 자기 시트 이름을 쓴 수식은 복제본 이름으로
        foreach ($final as $sheet) {
            if (! empty($sheet['clone_k']) && $sheet['doc'] !== null) {
                $this->renameInDocument($sheet['doc'], $sheet['name'], $sheet['final_name']);
            }
        }

        // 원본 시트 이름 변경을 모든 수식 / 이름 정의에 반영
        foreach ($finalOfOrig as $sheet) {
            if ($sheet['name'] === $sheet['final_name']) {
                continue;
            }

            foreach ($final as $other) {
                $isOwnClone = ! empty($other['clone_k']) && $other['orig_index'] === $sheet['orig_index'];
                if ($other['doc'] !== null && ! $isOwnClone) {
                    $this->renameInDocument($other['doc'], $sheet['name'], $sheet['final_name']);
                }
            }

            foreach ($xp->query('//m:definedNames/m:definedName') as $dn) {
                $dn->textContent = self::xmlText(FormulaRef::renameSheet($dn->textContent, $sheet['name'], $sheet['final_name']));
            }
        }

        $this->sheets = $final;
    }

    private function renameInDocument(DOMDocument $doc, $old, $new)
    {
        if ($old === $new) {
            return;
        }

        foreach ($this->xpath($doc)->query('//m:c/m:f | //m:formula | //m:formula1 | //m:formula2') as $f) {
            if ($f->textContent !== '') {
                $f->textContent = self::xmlText(FormulaRef::renameSheet($f->textContent, $old, $new));
            }
        }
    }

    // ------------------------------------------------------------------
    // 셀 값 채우기
    // ------------------------------------------------------------------

    private function fillDocument(DOMDocument $doc, array $values)
    {
        foreach ($this->cells($doc) as $c) {
            $this->fillCell($c, $values);
        }
    }

    private function fillText($text, array $values)
    {
        if ($text === null || strpos($text, '{{') === false) {
            return $text;
        }

        return preg_replace_callback(self::PLACEHOLDER, function ($m) use ($values) {
            return array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0];
        }, $text);
    }

    private function fillCell(DOMElement $c, array $values)
    {
        $text = $this->cellText($c);

        if ($text === null || strpos($text, '{{') === false) {
            return;
        }

        $new = $this->fillText($text, $values);

        if ($new === $text) {
            return;
        }

        // 셀 전체가 하나의 치환값이고 값이 숫자면 숫자 셀로
        $numeric = false;
        if (preg_match('/^\s*\{\{\s*(.+?)\s*\}\}\s*$/u', $text, $m) && array_key_exists($m[1], $values)) {
            $numeric = is_int($values[$m[1]]) || is_float($values[$m[1]]);
        }

        while ($c->firstChild) {
            $c->removeChild($c->firstChild);
        }

        $doc = $c->ownerDocument;

        if ($numeric) {
            $c->removeAttribute('t');
            $c->appendChild($doc->createElementNS(self::NS_MAIN, 'v', (string) $new));
            return;
        }

        $new = self::xmlText($new);
        if (mb_strlen($new) > 32767) {
            $new = mb_substr($new, 0, 32767);
        }

        $c->setAttribute('t', 'inlineStr');
        $is = $doc->createElementNS(self::NS_MAIN, 'is');
        $t = $doc->createElementNS(self::NS_MAIN, 't');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($doc->createTextNode($new));
        $is->appendChild($t);
        $c->appendChild($is);
    }

    private function cellText(DOMElement $c)
    {
        $type = $c->getAttribute('t');

        if ($type === 's') {
            $v = $c->getElementsByTagNameNS(self::NS_MAIN, 'v')->item(0);
            if ($v === null) {
                return null;
            }
            $index = (int) $v->textContent;
            return isset($this->shared[$index]) ? $this->shared[$index] : null;
        }

        if ($type === 'inlineStr') {
            $is = $c->getElementsByTagNameNS(self::NS_MAIN, 'is')->item(0);
            return $is ? self::richText($is) : null;
        }

        return null;
    }

    /**
     * <si> / <is> 의 텍스트 (서식 조각 <r><t> 합치기, 윗주 <rPh> 제외)
     */
    private static function richText(DOMElement $node)
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 't') {
                $text .= $child->textContent;
            } elseif ($child->localName === 'r') {
                foreach ($child->childNodes as $t) {
                    if ($t instanceof DOMElement && $t->localName === 't') {
                        $text .= $t->textContent;
                    }
                }
            }
        }

        return $text;
    }

    private function cells(DOMDocument $doc)
    {
        return $doc->getElementsByTagNameNS(self::NS_MAIN, 'c');
    }

    // ------------------------------------------------------------------
    // 로드 / 저장
    // ------------------------------------------------------------------

    private function load()
    {
        $this->contentTypes = $this->loadXml('[Content_Types].xml');

        $rootRels = $this->loadXml('_rels/.rels');
        $this->workbookPath = 'xl/workbook.xml';
        foreach ($rootRels->getElementsByTagNameNS(self::NS_PKG, 'Relationship') as $rel) {
            if (self::relType($rel->getAttribute('Type')) === 'officeDocument') {
                $this->workbookPath = ltrim(self::resolvePath('', $rel->getAttribute('Target')), '/');
            }
        }

        $this->workbook = $this->loadXml($this->workbookPath);
        $this->workbookRelsPath = self::relsPathFor($this->workbookPath);
        $this->workbookRels = $this->loadXml($this->workbookRelsPath);

        $targets = array();
        $wbDir = dirname($this->workbookPath);

        foreach ($this->workbookRels->getElementsByTagNameNS(self::NS_PKG, 'Relationship') as $rel) {
            $type = self::relType($rel->getAttribute('Type'));
            $target = self::resolvePath($wbDir, $rel->getAttribute('Target'));
            $targets[$rel->getAttribute('Id')] = array($type, $target);

            if ($type === 'sharedStrings') {
                $this->loadSharedStrings($target);
            }
        }

        foreach ($this->xpath($this->workbook)->query('//m:sheets/m:sheet') as $node) {
            $rid = $node->getAttributeNS(self::NS_R, 'id');
            if (! isset($targets[$rid])) {
                continue;
            }

            list($type, $path) = $targets[$rid];
            $doc = null;

            if ($type === 'worksheet') {
                $doc = $this->loadXml($path);
                $this->normalizeSheet($doc);
            }

            $this->sheets[] = array(
                'name' => $node->getAttribute('name'),
                'path' => $path,
                'doc' => $doc,
                'node' => $node,
            );
        }

        if (empty($this->sheets)) {
            throw new RuntimeException('No worksheet found');
        }
    }

    private function loadSharedStrings($path)
    {
        $xml = $this->zip->getFromName($path);
        if ($xml === false) {
            return;
        }

        $doc = new DOMDocument();
        $doc->loadXML($xml, LIBXML_PARSEHUGE);

        foreach ($doc->documentElement->childNodes as $si) {
            if ($si instanceof DOMElement && $si->localName === 'si') {
                $this->shared[] = self::richText($si);
            }
        }
    }

    /**
     * r 속성이 빠진 행/셀에 번호 부여
     */
    private function normalizeSheet(DOMDocument $doc)
    {
        $last = 0;
        foreach ($doc->getElementsByTagNameNS(self::NS_MAIN, 'row') as $row) {
            $r = $row->hasAttribute('r') ? (int) $row->getAttribute('r') : $last + 1;
            $row->setAttribute('r', $r);
            $last = $r;

            $col = 0;
            foreach ($row->childNodes as $c) {
                if ($c instanceof DOMElement && $c->localName === 'c') {
                    if ($c->hasAttribute('r')) {
                        list($col) = FormulaRef::parseCell($c->getAttribute('r'));
                    } else {
                        $col++;
                    }
                    $c->setAttribute('r', FormulaRef::cell($col, $r));
                }
            }
        }
    }

    private function save()
    {
        // 수식 캐시 값 제거 → 열 때 다시 계산
        foreach ($this->sheets as $sheet) {
            if ($sheet['doc'] === null) {
                continue;
            }

            foreach ($this->xpath($sheet['doc'])->query('//m:c[m:f]') as $c) {
                foreach ($this->xpath($sheet['doc'])->query('m:v', $c) as $v) {
                    $c->removeChild($v);
                }
                if (in_array($c->getAttribute('t'), array('str', 'b', 'e', 'n'), true)) {
                    $c->removeAttribute('t');
                }
            }

            $this->zip->addFromString($sheet['path'], $sheet['doc']->saveXML());
        }

        // calcChain 제거 + 열 때 전체 재계산
        $wbDir = dirname($this->workbookPath);
        foreach ($this->workbookRels->getElementsByTagNameNS(self::NS_PKG, 'Relationship') as $rel) {
            if (self::relType($rel->getAttribute('Type')) === 'calcChain') {
                $target = self::resolvePath($wbDir, $rel->getAttribute('Target'));
                $this->zip->deleteName($target);
                $this->removeOverride('/'.$target);
                $rel->parentNode->removeChild($rel);
                break;
            }
        }

        $xp = $this->xpath($this->workbook);
        $calcPr = $xp->query('/m:workbook/m:calcPr')->item(0);
        if (! $calcPr) {
            $calcPr = $this->workbook->createElementNS(self::NS_MAIN, 'calcPr');
            $before = null;
            foreach (array('oleSize', 'customWorkbookViews', 'pivotCaches', 'smartTagPr', 'smartTagTypes', 'webPublishing', 'fileRecoveryPr', 'webPublishObjects', 'extLst') as $tag) {
                $before = $xp->query('/m:workbook/m:'.$tag)->item(0);
                if ($before) {
                    break;
                }
            }
            if ($before) {
                $this->workbook->documentElement->insertBefore($calcPr, $before);
            } else {
                $this->workbook->documentElement->appendChild($calcPr);
            }
        }
        $calcPr->setAttribute('fullCalcOnLoad', '1');

        foreach ($this->docs as $path => $doc) {
            $this->zip->addFromString($path, $doc->saveXML());
        }

        foreach ($this->newFiles as $path => $content) {
            $this->zip->addFromString($path, $content);
        }

        $this->zip->addFromString($this->workbookPath, $this->workbook->saveXML());
        $this->zip->addFromString($this->workbookRelsPath, $this->workbookRels->saveXML());
        $this->zip->addFromString('[Content_Types].xml', $this->contentTypes->saveXML());

        $this->close();
    }

    // ------------------------------------------------------------------
    // 패키지 도우미
    // ------------------------------------------------------------------

    private function loadXml($path)
    {
        $xml = $this->zip->getFromName($path);

        if ($xml === false) {
            throw new RuntimeException('Missing part: '.$path);
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;

        if (! $doc->loadXML($xml, LIBXML_PARSEHUGE | LIBXML_NONET)) {
            throw new RuntimeException('Invalid XML: '.$path);
        }

        return $doc;
    }

    /**
     * 수정 대상 XML 파트 (캐시)
     */
    private function part($path)
    {
        if (! isset($this->docs[$path])) {
            $xml = $this->readPart($path);
            if ($xml === false || $xml === null) {
                return null;
            }
            $doc = new DOMDocument();
            $doc->preserveWhiteSpace = true;
            if (! @$doc->loadXML($xml, LIBXML_PARSEHUGE | LIBXML_NONET)) {
                return null;
            }
            $this->docs[$path] = $doc;
        }

        return $this->docs[$path];
    }

    private function readPart($path)
    {
        if (isset($this->newFiles[$path])) {
            return $this->newFiles[$path];
        }

        if (isset($this->docs[$path])) {
            return $this->docs[$path]->saveXML();
        }

        return $this->zip->getFromName($path);
    }

    private function partExists($path)
    {
        return isset($this->reserved[$path]) || isset($this->newFiles[$path]) || isset($this->docs[$path]) || $this->zip->locateName($path) !== false;
    }

    private function sheetRelationships($sheetPath)
    {
        $xml = $this->readPart(self::relsPathFor($sheetPath));
        $result = array();

        if (! $xml) {
            return $result;
        }

        $doc = new DOMDocument();
        $doc->loadXML($xml);

        foreach ($doc->getElementsByTagNameNS(self::NS_PKG, 'Relationship') as $rel) {
            if ($rel->getAttribute('TargetMode') !== 'External') {
                $result[] = array(
                    'type' => self::relType($rel->getAttribute('Type')),
                    'target' => self::resolvePath(dirname($sheetPath), $rel->getAttribute('Target')),
                );
            }
        }

        return $result;
    }

    private function uniquePartName($path)
    {
        $dir = dirname($path);
        $file = basename($path);
        $ext = '';

        if (($dot = strrpos($file, '.')) !== false) {
            $ext = substr($file, $dot);
            $file = substr($file, 0, $dot);
        }

        $base = preg_replace('/\d+$/', '', $file);
        $n = 1;

        do {
            $candidate = $dir.'/'.$base.$n.$ext;
            $n++;
        } while ($this->partExists($candidate));

        $this->reserved[$candidate] = true;

        return $candidate;
    }

    private function copyContentType($from, $to)
    {
        foreach ($this->contentTypes->getElementsByTagNameNS(self::NS_CT, 'Override') as $o) {
            if ($o->getAttribute('PartName') === '/'.$from) {
                $this->addOverride('/'.$to, $o->getAttribute('ContentType'));
                return;
            }
        }
    }

    private function addOverride($partName, $contentType)
    {
        $node = $this->contentTypes->createElementNS(self::NS_CT, 'Override');
        $node->setAttribute('PartName', $partName);
        $node->setAttribute('ContentType', $contentType);
        $this->contentTypes->documentElement->appendChild($node);
    }

    private function removeOverride($partName)
    {
        foreach ($this->contentTypes->getElementsByTagNameNS(self::NS_CT, 'Override') as $o) {
            if ($o->getAttribute('PartName') === $partName) {
                $o->parentNode->removeChild($o);
                return;
            }
        }
    }

    private function nextRelId(DOMDocument $rels)
    {
        $max = 0;
        foreach ($rels->getElementsByTagNameNS(self::NS_PKG, 'Relationship') as $rel) {
            if (preg_match('/^rId(\d+)$/', $rel->getAttribute('Id'), $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return 'rId'.($max + 1);
    }

    private function nextSheetId()
    {
        $max = 0;
        foreach ($this->xpath($this->workbook)->query('//m:sheets/m:sheet') as $node) {
            $max = max($max, (int) $node->getAttribute('sheetId'));
        }
        return $max + 1;
    }

    private function xpath(DOMDocument $doc)
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', self::NS_MAIN);
        $xp->registerNamespace('r', self::NS_R);
        return $xp;
    }

    // ------------------------------------------------------------------
    // 정적 도우미
    // ------------------------------------------------------------------

    private static function relType($type)
    {
        $pos = strrpos($type, '/');
        return $pos === false ? $type : substr($type, $pos + 1);
    }

    public static function relsPathFor($path)
    {
        $dir = dirname($path);
        return ($dir === '.' ? '' : $dir.'/').'_rels/'.basename($path).'.rels';
    }

    public static function resolvePath($baseDir, $target)
    {
        if ($target !== '' && $target[0] === '/') {
            $path = ltrim($target, '/');
        } else {
            $path = ($baseDir === '' || $baseDir === '.') ? $target : $baseDir.'/'.$target;
        }

        $parts = array();
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }

    public static function relativePath($fromDir, $toPath)
    {
        $from = $fromDir === '' || $fromDir === '.' ? array() : explode('/', $fromDir);
        $to = explode('/', $toPath);

        while (! empty($from) && count($to) > 1 && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)).implode('/', $to);
    }

    private static function sameName($a, $b)
    {
        return mb_strtolower($a) === mb_strtolower($b);
    }

    private static function shiftRow($row, $max, $delta)
    {
        return $row > $max ? $row + $delta : $row;
    }

    private static function shiftEnd($r1, $r2, $min, $max, $delta)
    {
        if ($r2 > $max) {
            return $r2 + $delta;
        }

        return ($r2 >= $min && $r1 <= $max) ? $r2 + $delta : $r2;
    }

    /**
     * 반복 묶음 바깥에서 본 참조 이동: 아래 행은 밀고, 묶음에서 끝나는 범위는 늘림
     */
    private static function shiftOutside(array $ref, $min, $max, $delta)
    {
        if (empty($ref['range'])) {
            $ref['r1'] = self::shiftRow($ref['r1'], $max, $delta);
            return $ref;
        }

        $r1 = min($ref['r1'], $ref['r2']);
        $r2 = max($ref['r1'], $ref['r2']);
        $ref['r1'] = self::shiftRow($r1, $max, $delta);
        $ref['r2'] = self::shiftEnd($r1, $r2, $min, $max, $delta);

        return $ref;
    }

    private static function shiftSqref($sqref, $min, $max, $delta)
    {
        $out = array();

        foreach (preg_split('/\s+/', trim($sqref)) as $part) {
            $range = self::parseRange($part);
            if ($range === null) {
                $out[] = $part;
                continue;
            }
            $out[] = self::rangeString($range[0], self::shiftRow($range[1], $max, $delta), $range[2], self::shiftEnd($range[1], $range[3], $min, $max, $delta), true);
        }

        return implode(' ', $out);
    }

    /**
     * "A1:C3" / "B2" -> [c1, r1, c2, r2]
     */
    private static function parseRange($ref)
    {
        $parts = explode(':', str_replace('$', '', $ref));
        list($c1, $r1) = FormulaRef::parseCell($parts[0]);
        if ($c1 === 0) {
            return null;
        }

        if (count($parts) === 2) {
            list($c2, $r2) = FormulaRef::parseCell($parts[1]);
            if ($c2 === 0) {
                return null;
            }
        } else {
            $c2 = $c1;
            $r2 = $r1;
        }

        return array($c1, $r1, $c2, $r2);
    }

    private static function rangeString($c1, $r1, $c2, $r2, $collapse = false)
    {
        $a = FormulaRef::cell($c1, $r1);
        $b = FormulaRef::cell($c2, $r2);

        return ($collapse && $a === $b) ? $a : $a.':'.$b;
    }

    public static function sanitizeSheetName($name)
    {
        $name = preg_replace('/[\[\]:*?\/\\\\\x00-\x1F]/u', ' ', (string) $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name), " '");
        $name = mb_substr($name, 0, 31);
        $name = trim($name, " '");

        return $name === '' ? 'Sheet' : $name;
    }

    /**
     * XML 1.0 에서 허용되지 않는 제어 문자 제거
     */
    public static function xmlText($text)
    {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $text);
    }
}
