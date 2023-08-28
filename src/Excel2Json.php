<?php
declare(strict_types=1);
namespace Glued\Lib;

use PhpOffice\PhpSpreadsheet\IOFactory;

class Excel2Json
{

    public function __construct()
    {
    }

    /**
     * Test if $array has only empty elements or elements filled solely with spaces. Return true/false accordingly.
     * @param array $array
     * @return bool
     */
    private function isAllEmptyOrSpaces(array $array): bool {
        $filteredArray = array_filter($array, function($value) {
            return $value === null || !empty(trim($value));
        });
        return empty($filteredArray);
    }


    /**
     * a php reimplementation of https://github.com/jiridudekusy/excel-as-json
     * Note that this implementation assumes that the square brackets will only ever be used to denote numeric indexes in
     * the key path, and that the indexes will always be non-negative integers. If that's not the case, you may need to
     * modify the regular expression used to extract the index or add additional checks to ensure the input is valid.
     * @param $filePath
     * @param $sheetName
     * @return false|string (json)
     */
    public function excel2array($filePath, $sheetName): array {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (is_null($sheet)) { throw new \Exception('Sheet '.$sheetName. ' not in file '.$filePath); }
        $sheetData = $spreadsheet->getSheetByName($sheetName)->toArray(null, true, true, true);

        // fill $jsonPath AS $jsonPath[$column][$path]
        $jsonPath = [];
        foreach ($sheetData[1] AS $col => $val) {
            $val = trim($val ?? ""); // `$val ?? ""` resolves case when $val is null
            if (!empty($val)) {
                $jsonPath[$col] = $val;
            }
        }

        $filtered = [];
        foreach ($sheetData AS $row) {
            $row = array_intersect_key($row, $jsonPath);
            if (!$this->isAllEmptyOrSpaces($row)) {
                foreach ($row AS $key => $value) {
                    if (substr($jsonPath[$key], -2) === '[]') {
                        $row[$key] = explode(';', $value ?? ""); // `$value ?? ""` resolves case when $value is null
                    }
                }
                $filtered[] = $row;
            }
        }
        // get rid of the first line
        array_shift($filtered);
        $sheetData = $filtered;

        // get the result
        $result = [];
        foreach ($sheetData AS $row) {
            $obj = [];
            foreach ($row AS $col => $val) {
                $path = explode(".", $jsonPath[$col]);
                $node = &$obj;
                foreach ($path AS $key) {
                    // if a key ends with [], it's treated AS a named array element
                    if (substr($key, -2) === '[]') {
                        $key = substr($key, 0, -2);
                        if (!isset($node[$key])) {
                            $node[$key] = [];
                        }
                        $node = &$node[$key];
                        $node[] = $val;
                    }
                    // if a key starts and ends with square brackets (e.g., [0], [1], etc.),
                    // it's treated AS an unnamed array element
                    else if (preg_match('/^\[(\d+)\]$/', $key, $matches)) {
                        $index = intval($matches[1]);
                        if (!isset($node[$index])) {
                            $node[$index] = [];
                        }
                        $node = &$node[$index];
                    }
                    // otherwise, the key is treated AS a regular object property
                    else {
                        if (!isset($node[$key])) {
                            $node[$key] = [];
                        }
                        $node = &$node[$key];
                    }
                }
                $node = $val;
            }
            $result[] = $obj;
        }
        return $result;
    }

    public function excel2json($filePath, $sheetName): string {
        $res = $this->excel2array($filePath, $sheetName) ?? [];
        return json_encode($res) ?? '';
    }


}
