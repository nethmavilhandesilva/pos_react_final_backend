<x-mail::message>
# 📋 Daily Sales Summary Report
**Process Date:** {{ $reportData['processLogDate'] }}

This report provides a comprehensive breakdown of today's sales, adjustments, and inventory movements. All records have been successfully archived to history.

<x-mail::button :url="config('app.url') . '/dashboard'">
Go to System Dashboard
</x-mail::button>

---

## 📊 1. Summary Weight Report (All Items)
*Overview of aggregated inventory movement and net costs.*

<x-mail::table>
| Item Name | Total Weight | Total Packs | Pack Due Cost | Net Total |
| :--- | :--- | :--- | :--- | :--- |
@foreach ($reportData['sales'] as $sale)
| **{{ $sale->item_name }}** | {{ number_format($sale->weight, 2) }} kg | {{ $sale->packs }} | Rs. {{ number_format($sale->packs * $sale->pack_due, 2) }} | **Rs. {{ number_format($sale->total - ($sale->packs * $sale->pack_due), 2) }}** |
@endforeach
| <hr> | <hr> | <hr> | <hr> | <hr> |
| **GRAND TOTALS** | **{{ number_format($reportData['totals']['total_weight'], 2) }} kg** | | | **Rs. {{ number_format($reportData['totals']['total_net_total'], 2) }}** |
</x-mail::table>

---

## 🧾 2. Processed Sales Summary (By Customer)
*Detailed breakdown grouped by customer and bill number.*

@foreach ($reportData['grouped_sales'] as $customerCode => $bills)
<div style="background-color: #f4f4f4; border-left: 5px solid #004d00; padding: 10px; margin-bottom: 5px;">
    <strong>පාරිභෝගිකයා (Customer): {{ $customerCode }}</strong>
</div>

@foreach ($bills as $billNo => $sales)
<div style="font-size: 13px; color: #555; margin-top: 10px; margin-bottom: 5px;">
    &nbsp;&nbsp;📄 බිල්පත් අංකය (Bill): <strong>{{ $billNo ?: 'N/A' }}</strong>
</div>

<x-mail::table>
| කේතය | භාණ්ඩ නාමය | බර | මිල | මලු | එකතුව |
| :--- | :--- | :---: | :---: | :---: | :---: |
@foreach ($sales as $sale)
| {{ $sale->code }} | {{ $sale->item_name }} | {{ number_format($sale->weight, 2) }} | {{ number_format($sale->price_per_kg, 2) }} | {{ $sale->packs }} | {{ number_format($sale->total, 2) }} |
@endforeach
| | | | | **Sub-Total** | **{{ number_format($sales->sum('total'), 2) }}** |
</x-mail::table>
@endforeach
@endforeach

---

## 📦 3. අයිතමය අනුව විස්තරාත්මක වාර්තාව (Item Details)
*Individual entry log for all items processed today.*

<x-mail::table>
| බිල් අංකය | මලු | බර (kg) | මිල (Rs) | එකතුව (Rs) | ගෙණුම්කරු | GRN |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
@foreach ($reportData['raw_sales'] as $item)
| {{ $item->bill_no }} | {{ $item->packs }} | {{ number_format($item->weight, 2) }} | {{ number_format($item->price_per_kg, 2) }} | {{ number_format($item->total, 2) }} | {{ $item->customer_code }} | `{{ $item->code }}` |
@endforeach
</x-mail::table>

---

## 🛠️ 4. විකුණුම් වෙනස් කිරීමේ වාර්තාව (Adjustments)
*Log of modified or deleted records.*

**Legend:** 🟢 Original &nbsp;&nbsp; 🟡 Updated &nbsp;&nbsp; 🔴 Deleted

<x-mail::table>
| විකුණුම්කරු | වර්ගය | බර | මිල | මලු | Status | දිනය/වේලාව |
| :--- | :--- | :---: | :---: | :---: | :--- | :--- |
@foreach ($reportData['adjustments'] as $adj)
@php
    $icon = $adj->type == 'original' ? '🟢' : ($adj->type == 'updated' ? '🟡' : '🔴');
@endphp
| {{ $adj->code }} | {{ $adj->item_name }} | {{ $adj->weight }} | {{ number_format($adj->price_per_kg, 2) }} | {{ $adj->packs }} | {{ $icon }} **{{ strtoupper($adj->type) }}** | {{ $adj->Date }} |
@endforeach
@if(count($reportData['adjustments']) == 0)
| | | *සටහන් කිසිවක් සොයාගෙන නොමැත* | | | | |
@endif
</x-mail::table>

---

<div style="text-align: right; font-size: 18px; margin-top: 20px;">
    <strong>Net Revenue: Rs. {{ number_format($reportData['totals']['total_net_total'], 2) }}</strong>
</div>

Best regards,<br>
**{{ config('app.name') }} Automated System**
</x-mail::message>