<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DynamicReportExport implements FromArray, WithHeadings, WithTitle, WithStyles
{
    protected $reportData;
    protected $headings;
    protected $reportTitle;
    protected $meta;

    public function __construct($reportData, $headings, $reportTitle, $meta)
    {
        $this->reportData = $reportData;
        $this->headings = $headings;
        $this->reportTitle = $reportTitle;
        $this->meta = $meta;
    }

    public function array(): array
    {
        return $this->reportData;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return substr($this->reportTitle, 0, 31); // Excel sheet title max 31 chars
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text
            1 => ['font' => ['bold' => true]],
        ];
    }
}