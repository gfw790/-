<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php analyze_work_standard.php <excel-file> [<excel-file> ...]\n");
    exit(1);
}

foreach (array_slice($argv, 1) as $file) {
    echo "===== FILE: {$file} =====\n";

    try {
        $spreadsheet = IOFactory::load($file);
    } catch (Throwable $e) {
        echo "LOAD_ERROR: {$e->getMessage()}\n";
        continue;
    }

    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $title = $sheet->getTitle();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        echo "[SHEET] {$title}\n";
        echo "DIMENSION: A1:{$highestColumn}{$highestRow}\n";

        $nonEmptyRows = 0;
        for ($row = 1; $row <= min($highestRow, 60); $row++) {
            $cells = [];
            for ($col = 1; $col <= min($highestColumnIndex, 40); $col++) {
                $coord = Coordinate::stringFromColumnIndex($col) . $row;
                $value = $sheet->getCell($coord)->getFormattedValue();
                $value = trim(str_replace(["\r", "\n"], ' ', (string) $value));
                if ($value === '') {
                    continue;
                }
                $cells[] = $coord . '=' . preg_replace('/\s+/u', ' ', $value);
            }

            if ($cells) {
                $nonEmptyRows++;
                echo 'ROW ' . $row . ': ' . implode(' | ', $cells) . "\n";
            }
        }

        echo "NON_EMPTY_ROWS_SHOWN: {$nonEmptyRows}\n";
        echo "\n";
    }
}
