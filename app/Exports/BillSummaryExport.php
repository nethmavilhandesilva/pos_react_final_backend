<?php
namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Carbon\Carbon;
use App\Models\GrnEntry;

class BillSummaryExport implements FromCollection, WithHeadings, ShouldAutoSize, WithTitle, WithEvents
{
    private $salesData;
    private $groupedData;
    private $exportData;

    public function __construct(Collection $salesData)
    {
        $this->salesData = $salesData;

        // Group by bill_no or customer_code
        $this->groupedData = $salesData->groupBy(function($sale) {
            return $sale->bill_no ?: $sale->customer_code;
        });

        $this->exportData = $this->prepareExportData();
    }

    private function prepareExportData()
    {
        $data = new Collection();
        $grandTotal = 0;

        foreach ($this->groupedData as $groupKey => $sales) {
            $isBill = !empty($sales->first()->bill_no);
            $billTotal = $sales->sum(fn($sale) => $sale->weight * $sale->price_per_kg);
            $billTotal2 = $sales->sum('total');
            $grandTotal += $billTotal2;

            $firstPrinted = $sales->first()->FirstTimeBillPrintedOn ?? null;
            $reprinted = $sales->first()->BillReprintAfterchanges ?? null;

            // Section header
            $data->push([
                ($isBill ? 'Bill No: '.$sales->first()->bill_no.' | ' : '').
                'Customer Code: '.$sales->first()->customer_code,
                ($firstPrinted ? 'First Printed: '.Carbon::parse($firstPrinted)->format('Y-m-d') : ''),
                ($reprinted ? 'Reprinted: '.Carbon::parse($reprinted)->format('Y-m-d') : '')
            ]);

            // Column headers
            $data->push(['Code','Item Name','Weight','Price/Kg','Packs','Total']);

            // Rows
            foreach ($sales as $sale) {
                $grn = GrnEntry::where('code', trim($sale->code))->first();
                $grnPrice = $grn ? (float)$grn->PerKGPrice : null;
                $highlight = $grnPrice !== null && $sale->price_per_kg < $grnPrice;

                $data->push([
                    $sale->code,
                    $sale->item_name,
                    number_format($sale->weight,2),
                    number_format($sale->price_per_kg,2).($highlight ? ' *' : ''), // mark lower prices
                    $sale->packs,
                    number_format($sale->weight * $sale->price_per_kg,2)
                ]);
            }

            // Section totals
            $data->push(['Total:','','','','',number_format($billTotal,2)]);
            $data->push(['Total with Packs:','','','','',number_format($billTotal2,2)]);
            $data->push([]); // empty row
        }

        // Grand total
        $data->push([
            'Grand Total:', '', '', '', '', number_format(  $grandTotal)
        ]);
        $data->push([
            'Total Records: '.$this->salesData->count(),
            'Total Groups: '.$this->groupedData->count()
        ]);

        return $data;
    }

    public function collection()
    {
        return $this->exportData;
    }

    public function headings(): array
    {
        return ['Customer / Bill Info','First Printed','Reprinted','','','','']; // main heading placeholders
    }

    public function title(): string
    {
        return 'Sales Report';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $rowIndex = 2; // start after main heading
                foreach ($this->groupedData as $sales) {
                    // Merge section header
                    $event->sheet->mergeCells('A'.$rowIndex.':F'.$rowIndex);
                    $event->sheet->getStyle('A'.$rowIndex)->applyFromArray([
                        'font' => ['bold' => true, 'size' => 11],
                        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'color' => ['rgb' => 'E0E0E0']]
                    ]);
                    $rowIndex++;

                    // Column headers style
                    $event->sheet->getStyle('A'.$rowIndex.':F'.$rowIndex)->applyFromArray([
                        'font' => ['bold'=>true],
                        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'color'=>['rgb'=>'F2F2F2']]
                    ]);
                    $rowIndex += $sales->count() + 3; // skip data + totals + empty
                }

                // Grand total style
                $lastRow = $this->exportData->count();
                $event->sheet->getStyle('A'.$lastRow.':F'.$lastRow)->applyFromArray([
                    'font' => ['bold'=>true,'size'=>12],
                    'fill' => ['fillType'=> \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'color'=>['rgb'=>'D3D3D3']]
                ]);
            }
        ];
    }
}
