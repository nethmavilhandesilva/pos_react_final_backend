<x-mail::message>

# **Daily Sales Summary Report**
---

<div style="font-size: 15px; color:#4a4a4a; line-height:1.6;">
    <strong>Date Processed:</strong> {{ $reportData['processLogDate'] }}<br>
    මෙම ලේඛනය සාරාංශ කරන්නේ දෛනික විකුණුම් මෙහෙයුම්, තොග චලන, සහ පද්ධතිය තුළ වාර්තා කරන ලද සංශෝධන ලොග් සටහන්.
</div>
## **බර අනුව වාර්තාව**
<div style="font-size: 13px; color:#6c6c6c; margin-bottom:5px;">සියල්ල සඳහා දෛනික එකතුව.</div>

<x-mail::table>
| අයිතමය | බර | මලු | මලු කුලිය | එකතුව |
|:--- |:---: |:---: |:---: |:---: |
@foreach ($reportData['sales'] as $sale)
| **{{ $sale->item_name }}** | {{ number_format($sale->weight, 2) }} | {{ $sale->packs }} | {{ number_format($sale->packs * $sale->pack_due, 2) }} | **{{ number_format($sale->total - ($sale->packs * $sale->pack_due), 2) }}** |
@endforeach
| --- | --- | --- | --- | --- |
| **මුළු එකතුව** | **{{ number_format($reportData['totals']['total_weight'], 2) }}** |  |  | **{{ number_format($reportData['totals']['total_net_total'], 2) }}** |
</x-mail::table>

---

## **2. විකුණුම් සාරාංශය**
<div style="font-size: 13px; color:#6c6c6c; margin-bottom:5px;">ගනුදෙනුකරු සහ බිල් අංකය අනුව කණ්ඩායම්ගත කළ විකුණුම්.</div>

@foreach ($reportData['grouped_sales'] as $customerCode => $bills)

<div style="background:#f5f5f5; border-left:4px solid #0a6b28; padding:10px; margin-bottom:8px; font-size:14px;">
    <strong>පාරිභෝගික:</strong> {{ $customerCode }}
</div>

@foreach ($bills as $billNo => $sales)

<div style="font-size: 13px; color:#4a4a4a; margin:4px 0;">
    <strong>බිල් අංකය:</strong> {{ $billNo ?: 'N/A' }}
</div>

<x-mail::table>
| කේතය | අයිතමය | බර | මිල | මලු | එකතුව |
|:--- |:--- |:---: |:---: |:---: |:---: |
@foreach ($sales as $sale)
| {{ $sale->code }} | {{ $sale->item_name }} | {{ number_format($sale->weight, 2) }} | {{ number_format($sale->price_per_kg, 2) }} | {{ $sale->packs }} | {{ number_format($sale->total, 2) }} |
@endforeach
|  |  |  |  | **එකතුව** | **{{ number_format($sales->sum('total'), 2) }}** |
</x-mail::table>

@endforeach
@endforeach

---

## **3.අයිතම මට්ටමේ සවිස්තර ගත වාර්තා**
<div style="font-size: 13px; color:#6c6c6c; margin-bottom:5px;">Complete log of all sales entries captured during the process.</div>

<x-mail::table>
| බිල් අංකය | මලු | බර | මිල | එකතුව | පාරිභෝගික | කේතය |
|:--- |:---: |:---: |:---: |:---: |:---: |:---: |
@foreach ($reportData['raw_sales'] as $item)
| {{ $item->bill_no }} | {{ $item->packs }} | {{ number_format($item->weight, 2) }} | {{ number_format($item->price_per_kg, 2) }} | {{ number_format($item->total, 2) }} | {{ $item->customer_code }} | `{{ $item->code }}` |
@endforeach
</x-mail::table>

---

## **4.සංශෝධන වාර්තා**
<div style="font-size: 13px; color:#6c6c6c; margin-bottom:5px;">Corrections or modifications applied during review.</div>

<x-mail::table>
| කේතය | අයිතමය | බර | මිල | මලු | ස්ථානය | වේලාපත්‍රය |
|:--- |:--- |:---: |:---: |:---: |:--- |:--- |
@foreach ($reportData['adjustments'] as $adj)
@php
$statusColor = $adj->type === 'original' ? '#0a6b28' : ($adj->type === 'updated' ? '#c1a000' : '#c40000');
@endphp
| {{ $adj->code }} | {{ $adj->item_name }} | {{ $adj->weight }} | {{ number_format($adj->price_per_kg, 2) }} | {{ $adj->packs }} | <span style="color: {{ $statusColor }}; font-weight: bold;">{{ strtoupper($adj->type) }}</span> | {{ $adj->Date }} |
@endforeach

@if(count($reportData['adjustments']) == 0)
|  |  | *සංශෝධන වාර්තා නොවේ* |  |  |  |  |
@endif
</x-mail::table>
<br><br>

Regards,<br>
<strong>{{ config('app.name') }} Automated System</strong>

</x-mail::message>
