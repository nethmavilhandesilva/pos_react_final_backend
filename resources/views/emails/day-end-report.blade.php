{{-- resources/views/emails/day-end-report.blade.php --}}

<x-mail::message>
# Daily Sales Process Complete

The End-of-Day Sales Process was successfully executed.  
**{{ $reportData['totalRecordsMoved'] }}** sales records were moved from the `sales` table to `sales_history`.

**Process Date:** **{{ $reportData['processLogDate'] }}**

<br>

## 📊 Summary of Weight Report

Below is the aggregated sales data for the day:

<x-mail::table>
| Item Name | Total Weight (kg) | Total Packs | Pack Due Cost | Net Total |
| :--- | ---: | ---: | ---: | ---: |
@foreach ($reportData['sales'] as $sale)
@php
    $pack_due_cost = $sale->packs * $sale->pack_due;
    $net = $sale->total - $pack_due_cost;
@endphp
| {{ $sale->item_name }} | {{ number_format($sale->weight, 2) }} | {{ $sale->packs }} | {{ number_format($pack_due_cost, 2) }} | {{ number_format($net, 2) }} |
@endforeach
</x-mail::table>

<br>

**TOTAL WEIGHT:** **{{ number_format($reportData['totals']['total_weight'], 2) }}**  
**GRAND NET TOTAL:** **{{ number_format($reportData['totals']['total_net_total'], 2) }}**

<br>

Thanks,  
{{ config('app.name') }}
</x-mail::message>
