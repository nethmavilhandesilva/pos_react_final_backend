<?php
namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class GrnOverviewExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    private $reportData;
    private $totals = [];

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
        $this->calculateTotals();
    }

    public function collection()
    {
        return new Collection($this->reportData);
    }

    public function headings(): array
    {
        return [
            'Item Name',
            'Original Weight',
            'Original Packs',
            'Sold Weight',
            'Sold Packs',
            'Total Sales Value',
            'Remaining Weight',
            'Remaining Packs'
        ];
    }

    public function map($data): array
    {
        return [
            $data['item_name'],
            $data['original_weight'],
            $data['original_packs'],
            $data['sold_weight'],
            $data['sold_packs'],
            $data['total_sales_value'],
            $data['remaining_weight'],
            $data['remaining_packs']
        ];
    }

    private function calculateTotals()
    {
        $this->totals = [
            'original_weight' => 0,
            'original_packs' => 0,
            'sold_weight' => 0,
            'sold_packs' => 0,
            'total_sales_value' => 0,
            'remaining_weight' => 0,
            'remaining_packs' => 0,
        ];

        foreach ($this->reportData as $data) {
            $this->totals['original_weight'] += floatval($data['original_weight'] ?? 0);
            $this->totals['original_packs'] += floatval($data['original_packs'] ?? 0);
            $this->totals['sold_weight'] += floatval($data['sold_weight'] ?? 0);
            $this->totals['sold_packs'] += floatval($data['sold_packs'] ?? 0);
            $this->totals['total_sales_value'] += floatval($data['total_sales_value'] ?? 0);
            $this->totals['remaining_weight'] += floatval($data['remaining_weight'] ?? 0);
            $this->totals['remaining_packs'] += floatval($data['remaining_packs'] ?? 0);
        }
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                // Get the last row number
                $lastRow = count($this->reportData) + 1; // +1 for header row

                // Add totals row
                $event->sheet->append([
                    [
                        'TOTAL',
                        $this->totals['original_weight'],
                        $this->totals['original_packs'],
                        $this->totals['sold_weight'],
                        $this->totals['sold_packs'],
                        $this->totals['total_sales_value'],
                        $this->totals['remaining_weight'],
                        $this->totals['remaining_packs']
                    ]
                ]);

                // Style the totals row
                $totalsRow = $lastRow + 1;
                $event->sheet->getStyle("A{$totalsRow}:H{$totalsRow}")->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'FFFFFF']
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'color' => ['rgb' => '366092']
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000']
                        ]
                    ]
                ]);

                // Auto-size columns for better readability
                foreach (range('A', 'H') as $column) {
                    $event->sheet->getColumnDimension($column)->setAutoSize(true);
                }

                // Style the header row
                $event->sheet->getStyle('A1:H1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'FFFFFF']
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'color' => ['rgb' => '4F81BD']
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000']
                        ]
                    ]
                ]);

                // Add number formatting for numeric columns
                $event->sheet->getStyle("B2:B{$totalsRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $event->sheet->getStyle("D2:D{$totalsRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $event->sheet->getStyle("F2:F{$totalsRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $event->sheet->getStyle("G2:G{$totalsRow}")->getNumberFormat()->setFormatCode('#,##0.00');

                // Format sales value as currency
                $event->sheet->getStyle("F2:F{$totalsRow}")->getNumberFormat()->setFormatCode('"Rs."#,##0.00');

                // Add borders to all data cells
                $event->sheet->getStyle("A1:H{$totalsRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000']
                        ]
                    ]
                ]);
            },
        ];
    }
}