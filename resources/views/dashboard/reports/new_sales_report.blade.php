{{-- resources/views/dashboard/reports/new_sales_report.blade.php --}}
@extends('layouts.app')

@section('content')

@php
use Illuminate\Support\Str;
@endphp

<style>
    body { background-color: #99ff99; }

    /* ===== PRINT SETTINGS ===== */
    @media print {
        @page { size: A4 portrait; margin: 15mm; }
        body * { visibility: hidden; }
        .custom-card, .custom-card * { visibility: visible; }
        .custom-card {
            position: absolute;
            left: 0;
            top: 0;
            width: 210mm;
            min-height: 297mm;
            margin: auto;
            border: none !important;
            box-shadow: none !important;
        }
        body, .custom-card, .custom-card table, .custom-card th, .custom-card td {
            background: white !important;
            color: black !important;
        }
        .print-btn, .btn { display: none !important; }
        table { page-break-inside: auto; border-collapse: collapse !important; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
    }

    /* ===== SCREEN STYLES ===== */
    .custom-card { background-color: #006400; color: white; padding: 1rem; }
    table.table { font-size: 0.9rem; width: 100%; border-collapse: collapse; }
    table.table th, table.table td { padding: 0.3rem 0.6rem; vertical-align: middle; border: 1px solid #004d00; }
    table.table thead { background-color: #004d00; }
    table.table tbody tr:nth-child(odd) { background-color: #00550088; }
    table.table tbody tr:nth-child(even) { background-color: transparent; }
    .report-title-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
    .company-name { font-weight: 700; font-size: 1.5rem; color: white; margin: 0; }
    .right-info { color: white; font-weight: 600; text-align: right; font-size: 0.85rem; }
    .print-btn {
        background-color: #004d00; color: white; border: none; padding: 0.3rem 0.8rem;
        border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: background-color 0.3s ease;
    }
    .print-btn:hover { background-color: #003300; }

    /* Highlight style */
    .highlight-red {
        background-color: #ffcccc !important;
        color: #880000;
        font-weight: bold;
    }
</style>

<div class="container mt-2" style="min-height: 100vh; padding: 15px;">
    <div class="card custom-card shadow border-0 rounded-3">

        {{-- Report Header --}}
        <div class="report-title-bar">
            <h2 class="company-name">Sales Report</h2>
            <h4 class="fw-bold">Processed Sales Summary</h4>

            @php $settingDate = \App\Models\Setting::value('value'); @endphp
            <div class="right-info">
                <span>Report Date: {{ \Carbon\Carbon::parse($settingDate)->format('Y-m-d') }}</span>
            </div>

            <button class="print-btn" onclick="window.print()">🖨️ Print</button>
        </div>

        <div class="card-body p-0">
            @if ($salesData->isEmpty())
                <div class="alert alert-info m-3">No processed sales records found.</div>
            @else
                @php 
                    $groupedData = $salesData->groupBy(function($sale) {
                        return $sale->bill_no ?: $sale->customer_code;
                    });
                    $grandTotal = 0;
                @endphp

                @foreach ($groupedData as $groupKey => $sales)
                    @php
                        $isBill = !empty($sales->first()->bill_no);
                        $billTotal = $sales->sum(function($sale) {
    return $sale->weight * $sale->price_per_kg;
});

                        $billTotal2 = $sales->sum('total');
                        $firstPrinted = $sales->first()->FirstTimeBillPrintedOn ?? null;
                        $reprinted = $sales->first()->BillReprintAfterchanges ?? null;
                    @endphp

                    {{-- Header Section --}}
                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-1">
                            @if ($isBill)
                                Bill No: {{ $sales->first()->bill_no }}
                                <span class="ms-3 text-white">
                                    Customer Code: {{ $sales->first()->customer_code ?? '-' }}
                                </span>
                            @else
                                Customer Code: {{ $sales->first()->customer_code ?? '-' }}
                            @endif
                        </h5>
                        @if ($isBill)
                        <div class="right-info">
                            @if($firstPrinted)
                                <span>First Printed: {{ \Carbon\Carbon::parse($firstPrinted)->format('Y-m-d') }}</span>
                            @endif
                            @if($reprinted)
                                <span>Reprinted: {{ \Carbon\Carbon::parse($reprinted)->format('Y-m-d') }}</span>
                            @endif
                        </div>
                        @endif
                    </div>

                    {{-- Sales Table --}}
                    <table class="table table-bordered table-striped table-hover table-sm mb-3">
                        <thead class="text-center">
                            <tr>
                                <th>කේතය</th>
                                <th>භාණ්ඩ නාමය</th>
                                <th>බර</th>
                                <th>කිලෝවකට මිල</th>
                                <th>මලු</th>
                                <th>එකතුව</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sales as $sale)
                                @php
                                    $grn = \App\Models\GrnEntry::where('code', trim($sale->code))->first();
                                    $grnPrice = $grn ? (float) $grn->PerKGPrice : null;
                                    $isLower = $grnPrice !== null && $sale->price_per_kg < $grnPrice;
                                @endphp
                                <tr class="text-center">
                                    <td>{{ $sale->code }}</td>
                                    <td class="text-start">{{ $sale->item_name }}</td>
                                    <td>{{ number_format($sale->weight, 2) }}</td>
                                    <td style="{{ $isLower ? 'color:red; font-weight:bold;' : '' }}">
                                        {{ number_format($sale->price_per_kg, 2) }}
                                    </td>
                                    <td>{{ $sale->packs }}</td>
                                    <td>{{ number_format($sale->weight*$sale->price_per_kg, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold text-center">
                                <td colspan="5" class="text-end">Total:</td>
                                <td>{{ number_format($billTotal, 2) }}</td>
                            </tr>
                             <tr class="fw-bold text-center">
                                <td colspan="5" class="text-end">Total with Pack Cost:</td>
                                <td>{{ number_format($billTotal2, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>

                    @php $grandTotal += $billTotal2; @endphp
                @endforeach

                {{-- Grand Total --}}
                <div class="text-end fw-bold mt-3 me-3">
                    <h3>Grand Total: {{ number_format($grandTotal, 2) }}</h3>
                </div>
            @endif
        </div>
    </div>

    {{-- Export Buttons --}}
    <div class="mt-3">
        <a href="{{ route('sales.report.download', array_merge(request()->all(), ['format' => 'excel'])) }}" class="btn btn-success me-2">Download Excel</a>
        <a href="{{ route('sales.report.download', array_merge(request()->all(), ['format' => 'pdf'])) }}" class="btn btn-danger">Download PDF</a>
    </div>
</div>

@endsection
