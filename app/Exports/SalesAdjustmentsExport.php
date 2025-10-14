<?php

namespace App\Exports;

use App\Models\Salesadjustment;
use App\Models\Setting;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SalesAdjustmentsExport implements FromCollection, WithHeadings
{
    protected $code;
    protected $startDate;
    protected $endDate;
    protected $settingDate;

    public function __construct($code, $startDate, $endDate, $settingDate = null)
    {
        $this->code = $code;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->settingDate = $settingDate;
    }

    public function collection()
    {
        $query = Salesadjustment::query();

        if ($this->code) {
            $query->where('code', $this->code);
        }
        if ($this->startDate) {
            $query->whereDate('created_at', '>=', $this->startDate);
        }
        if ($this->endDate) {
            $query->whereDate('created_at', '<=', $this->endDate);
        }

        // ✅ Apply the setting date filter (same as PDF logic)
        if ($this->settingDate) {
            $query->whereDate('Date', $this->settingDate);
        } else {
            $defaultDate = Setting::value('value') ?? now()->toDateString();
            $query->whereDate('Date', $defaultDate);
        }

        // Fetch the filtered data
        $entries = $query->orderBy('created_at', 'desc')->get();

        // ✅ Map data for Excel
        return $entries->map(function ($entry) {
            $date = $entry->created_at->timezone('Asia/Colombo')->format('Y-m-d H:i');
            if ($entry->type === 'original' && $entry->original_created_at) {
                $date = \Carbon\Carbon::parse($entry->original_created_at)
                    ->timezone('Asia/Colombo')
                    ->format('Y-m-d H:i');
            }

            return [
                'විකුණුම්කරු' => $entry->code,
                'මලු' => $entry->packs,
                'වර්ගය' => $entry->item_name,
                'බර' => $entry->weight,
                'මිල' => number_format($entry->price_per_kg, 2),
                'මුළු මුදල' => number_format($entry->total, 2),
                'බිල්පත් අංකය' => $entry->bill_no,
                'පාරිභෝගික කේතය' => strtoupper($entry->customer_code),
                'වර්ගය (type)' => $entry->type,
                'දිනය සහ වේලාව' => $date,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'විකුණුම්කරු',
            'මලු',
            'වර්ගය',
            'බර',
            'මිල',
            'මුළු මුදල',
            'බිල්පත් අංකය',
            'පාරිභෝගික කේතය',
            'වර්ගය',
            'දිනය සහ වේලාව',
        ];
    }
}
