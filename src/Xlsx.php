<?php
declare(strict_types=1);

namespace DormCheck;

/** Minimal OOXML writer. Only explicit inline-string and numeric cells are emitted. */
final class Xlsx
{
    /** @param list<array{name:string,rows:list<list<mixed>>}> $sheets */
    public static function write(array $sheets, string $path): void
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\XMLWriter::class)) {
            throw new \RuntimeException('Excel 导出需要 PHP zip 和 xmlwriter 扩展。');
        }
        if ($sheets === [] || count($sheets) > 30) {
            throw new \InvalidArgumentException('工作表数量无效。');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建 Excel 临时文件。');
        }
        try {
            $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook = self::xml();
            $workbook->startElementNS(null, 'workbook', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $workbook->writeAttribute('xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $workbook->startElement('sheets');
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $usedNames = [];
            foreach (array_values($sheets) as $i => $sheet) {
                $number = $i + 1;
                $name = preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $sheet['name']) ?? '工作表';
                $name = mb_substr(trim($name), 0, 31);
                $name = $name === '' ? '工作表' . $number : $name;
                if (isset($usedNames[$name])) {
                    $name = mb_substr($name, 0, 27) . '_' . $number;
                }
                $usedNames[$name] = true;
                $workbook->startElement('sheet');
                $workbook->writeAttribute('name', $name);
                $workbook->writeAttribute('sheetId', (string) $number);
                $workbook->writeAttribute('r:id', 'rId' . $number);
                $workbook->endElement();
                $rels .= '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
                $types .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                if (!$zip->addFromString('xl/worksheets/sheet' . $number . '.xml', self::sheet($sheet['rows']))) {
                    throw new \RuntimeException('无法写入 Excel 工作表。');
                }
            }
            $workbook->endElement();
            $workbook->endElement();
            $workbook->endDocument();
            $rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
            $zip->addFromString('[Content_Types].xml', $types . '</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', $workbook->outputMemory());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
            $zip->addFromString('xl/styles.xml', self::styles());
        } finally {
            if (!$zip->close()) {
                throw new \RuntimeException('Excel 文件写入失败。');
            }
        }
    }

    private static function xml(): \XMLWriter
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        return $xml;
    }

    /** @param list<list<mixed>> $rows */
    private static function sheet(array $rows): string
    {
        if (count($rows) > 50000) {
            throw new \RuntimeException('单次导出记录过多，请缩小日期范围。');
        }
        $xml = self::xml();
        $xml->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $columns = 1;
        foreach ($rows as $row) {
            $columns = max($columns, count($row));
        }
        if ($columns > 100) {
            throw new \RuntimeException('Excel 列数超出限制。');
        }
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('ySplit', '1');
        $xml->writeAttribute('topLeftCell', 'A2');
        $xml->writeAttribute('activePane', 'bottomLeft');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('cols');
        for ($i = 1; $i <= $columns; $i++) {
            $xml->startElement('col');
            $xml->writeAttribute('min', (string) $i);
            $xml->writeAttribute('max', (string) $i);
            $xml->writeAttribute('width', $i === 1 ? '23' : '22');
            $xml->writeAttribute('customWidth', '1');
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('sheetData');
        foreach (array_values($rows) as $r => $row) {
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) ($r + 1));
            $xml->writeAttribute('ht', $r === 0 ? '28' : '24');
            $xml->writeAttribute('customHeight', '1');
            foreach (array_values($row) as $c => $value) {
                $xml->startElement('c');
                $xml->writeAttribute('r', self::column($c + 1) . ($r + 1));
                $xml->writeAttribute('s', $r === 0 ? '1' : '2');
                // Identifiers must be passed as strings. Never infer formula or numeric types.
                if (is_int($value) || (is_float($value) && is_finite($value))) {
                    $xml->writeAttribute('t', 'n');
                    $xml->writeElement('v', (string) $value);
                } else {
                    $xml->writeAttribute('t', 'inlineStr');
                    $xml->startElement('is');
                    $xml->startElement('t');
                    $xml->writeAttribute('xml:space', 'preserve');
                    $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
                    $xml->text(mb_substr($text, 0, 32767));
                    $xml->endElement();
                    $xml->endElement();
                }
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        if ($rows !== []) {
            $xml->startElement('autoFilter');
            $xml->writeAttribute('ref', 'A1:' . self::column($columns) . count($rows));
            $xml->endElement();
        }
        $xml->startElement('pageMargins');
        foreach (['left' => '0.3', 'right' => '0.3', 'top' => '0.5', 'bottom' => '0.5', 'header' => '0.2', 'footer' => '0.2'] as $key => $value) {
            $xml->writeAttribute($key, $value);
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory();
    }

    private static function column(int $number): string
    {
        $result = '';
        while ($number > 0) {
            $number--;
            $result = chr(65 + ($number % 26)) . $result;
            $number = intdiv($number, 26);
        }
        return $result;
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Microsoft YaHei"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Microsoft YaHei"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1473E6"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
