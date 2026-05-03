<x-mail::message>

# **Daily Sales & Payment Collection Report**
---

<div style="font-size: 15px; color:#4a4a4a; line-height:1.6;">
    <strong>Date Processed:</strong> {{ $reportData['processLogDate'] }}<br>
    <strong>Generated on:</strong> {{ now()->format('Y-m-d H:i:s') }}<br>
    <strong>Total Records Processed:</strong> {{ $reportData['totalRecordsMoved'] }}<br>
    මෙම ලේඛනය සාරාංශ කරන්නේ දෛනික විකුණුම් මෙහෙයුම්, ගෙවීම් එකතු කිරීම්, තොග චලන, සහ පද්ධතිය තුළ වාර්තා කරන ලද සංශෝධන ලොග් සටහන්.
</div>

---

## **1. බර අනුව වාර්තාව (Summary by Weight)**

<x-mail::table>
| අයිතමය | බර | මලු | මලු කුලිය | එකතුව |
|:--- |:---: |:---: |:---: |:---: |
@foreach ($reportData['sales'] as $sale)
| **{{ $sale->item_name }}** | {{ number_format($sale->weight, 2) }} | {{ $sale->packs }} | {{ number_format($sale->packs * $sale->pack_cost, 2) }} | **{{ number_format($sale->total - ($sale->packs * $sale->pack_cost), 2) }}** |
@endforeach
| --- | --- | --- | --- | --- |
| **මුළු එකතුව** | **{{ number_format($reportData['totals']['total_weight'], 2) }}** |  |  | **{{ number_format($reportData['totals']['total_net_total'], 2) }}** |
</x-mail::table>

---

## **2. විකුණුම් සාරාංශය (Customer → Bill → Items)**

@foreach ($reportData['grouped_sales'] as $customerCode => $bills)

<div style="background:#f5f5f5; border-left:4px solid #0a6b28; padding:10px; margin-bottom:8px;">
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

## **3. අයිතම මට්ටමේ සවිස්තර ගත වාර්තා (Raw Sales Log)**

<x-mail::table>
| බිල් අංකය | මලු | බර | මිල | එකතුව | පාරිභෝගික | කේතය |
|:--- |:---: |:---: |:---: |:---: |:---: |:---: |
@foreach ($reportData['raw_sales'] as $item)
| {{ $item->bill_no }} | {{ $item->packs }} | {{ number_format($item->weight, 2) }} | {{ number_format($item->price_per_kg, 2) }} | {{ number_format($item->total, 2) }} | {{ $item->customer_code }} | `{{ $item->code }}` |
@endforeach
</x-mail::table>

---

## **4. සංශෝධන වාර්තා (Adjustment Logs)**

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

---

## **5. සැපයුම්කරු අනුව වාර්තාව (Supplier Report)**

@foreach ($reportData['supplier_report'] as $supplierCode => $records)

<div style="background:#f2f2f2; border-left:4px solid #004d00; margin:10px 0; padding:10px;">
    <strong>සැපයුම්කරු: {{ $supplierCode }}</strong>
</div>

<x-mail::table>
| දිනය | අයිතම කේතය | අයිතමය | ගනුදෙනුකරු | බර | මිල | එකතුව | ලාභ |
|:--- |:--- |:--- |:--- |:---: |:---: |:---: |:---: |
@foreach ($records as $row)
| {{ $row->Date }} | {{ $row->item_code }} | {{ $row->item_name }} | {{ $row->customer_code }} | {{ $row->SupplierWeight }} | {{ $row->SupplierPricePerKg }} | {{ number_format($row->SupplierTotal, 2) }} | {{ number_format($row->profit, 2) }} |
@endforeach
</x-mail::table>

<div style="text-align:right; margin-bottom:20px;">
    <strong>සැපයුම්කරුගේ උප එකතුව:</strong>
    {{ number_format($records->sum('SupplierTotal'), 2) }}
</div>

@endforeach

---

## **6. Payment Collection Report (ගෙවීම් එකතු කිරීමේ වාර්තාව)**

<div style="background:#f0fdf4; padding:15px; border-radius:8px; margin:20px 0;">
    <h3 style="margin:0 0 10px 0;">📊 Payment Summary Statistics</h3>
    <table style="width:100%; border-collapse:collapse;">
        <tr style="border-bottom:1px solid #ddd;">
            <td style="padding:8px;"><strong>💰 Cash Collection:</strong></td>
            <td style="padding:8px; text-align:right;"><strong style="color:#10b981;">Rs {{ number_format($reportData['payment_totals']['cash_collection'] ?? 0, 2) }}</strong></td>
        </tr>
        <tr style="border-bottom:1px solid #ddd;">
            <td style="padding:8px;"><strong>💳 Cheques Collection:</strong></td>
            <td style="padding:8px; text-align:right;"><strong style="color:#8b5cf6;">Rs {{ number_format($reportData['payment_totals']['cheques_collection'] ?? 0, 2) }}</strong></td>
         </tr>
        <tr style="border-bottom:1px solid #ddd;">
            <td style="padding:8px;"><strong>📦 Bag/Box Total:</strong></td>
            <td style="padding:8px; text-align:right;"><strong style="color:#f59e0b;">Rs {{ number_format($reportData['payment_totals']['bag_box_total'] ?? 0, 2) }}</strong></td>
         </tr>
        <tr style="border-bottom:1px solid #ddd;">
            <td style="padding:8px;"><strong>🏦 Bank Transfer:</strong></td>
            <td style="padding:8px; text-align:right;"><strong style="color:#ec489a;">Rs {{ number_format($reportData['payment_totals']['banks_transfer'] ?? 0, 2) }}</strong></td>
         </tr>
        <tr style="border-bottom:1px solid #ddd;">
            <td style="padding:8px;"><strong>⚠️ Bad Debt:</strong></td>
            <td style="padding:8px; text-align:right;"><strong style="color:#ef4444;">Rs {{ number_format($reportData['payment_totals']['bad_debt'] ?? 0, 2) }}</strong></td>
         </tr>
    </table>     
     <div style="margin-top:10px; background:#fef3c7; padding:10px; border-radius:6px; text-align:center;">
         <strong>Grand Total Collected:</strong> 
         Rs {{ number_format(
             ($reportData['payment_totals']['cash_collection'] ?? 0) + 
             ($reportData['payment_totals']['cheques_collection'] ?? 0) + 
             ($reportData['payment_totals']['bag_box_total'] ?? 0) + 
             ($reportData['payment_totals']['banks_transfer'] ?? 0), 2) }}
     </div>
</div>

### **Detailed Payment Collection by Bill**

<x-mail::table>
| Customer/Bill No | Cash | Cheques | Bag/Box | Bags | Boxes | Bank Transfer | Bad Debt |
|:--- |:---: |:---: |:---: |:---: |:---: |:---: |:---: |
@foreach($reportData['payment_data'] as $row)
| {{ $row['customer_bill_no'] }} | Rs {{ number_format($row['cash_collection'], 2) }} | Rs {{ number_format($row['cheques_collection'], 2) }} | Rs {{ number_format($row['bag_box_total'], 2) }} | {{ $row['bag_total'] }} | {{ $row['box_total'] }} | Rs {{ number_format($row['banks_transfer'], 2) }} | Rs {{ number_format($row['bad_debt'], 2) }} |
@endforeach
| **TOTAL** | **Rs {{ number_format($reportData['payment_totals']['cash_collection'] ?? 0, 2) }}** | **Rs {{ number_format($reportData['payment_totals']['cheques_collection'] ?? 0, 2) }}** | **Rs {{ number_format($reportData['payment_totals']['bag_box_total'] ?? 0, 2) }}** | **{{ number_format($reportData['payment_totals']['bag_total'] ?? 0) }}** | **{{ number_format($reportData['payment_totals']['box_total'] ?? 0) }}** | **Rs {{ number_format($reportData['payment_totals']['banks_transfer'] ?? 0, 2) }}** | **Rs {{ number_format($reportData['payment_totals']['bad_debt'] ?? 0, 2) }}** |
</x-mail::table>

<div style="margin-top:20px;">
    <p><strong>Total Bills Processed:</strong> {{ count($reportData['payment_data']) }}</p>
</div>

---

## **7. Report Summary**

<div style="background:#e0e7ff; padding:15px; border-radius:8px; margin-top:20px;">
    <table style="width:100%;">
        <tr>
            <td style="padding:5px;"><strong>Total Sales Amount:</strong></td>
            <td style="padding:5px; text-align:right;">Rs {{ number_format($reportData['totals']['total_net_total'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td style="padding:5px;"><strong>Total Payment Collected:</strong></td>
            <td style="padding:5px; text-align:right;">Rs {{ number_format(
                ($reportData['payment_totals']['cash_collection'] ?? 0) + 
                ($reportData['payment_totals']['cheques_collection'] ?? 0) + 
                ($reportData['payment_totals']['bag_box_total'] ?? 0) + 
                ($reportData['payment_totals']['banks_transfer'] ?? 0), 2) }}</td>
        </tr>
        <tr>
            <td style="padding:5px;"><strong>Total Outstanding:</strong></td>
            <td style="padding:5px; text-align:right; color:#ef4444;">
                Rs {{ number_format(($reportData['totals']['total_net_total'] ?? 0) - 
                    (($reportData['payment_totals']['cash_collection'] ?? 0) + 
                     ($reportData['payment_totals']['cheques_collection'] ?? 0) + 
                     ($reportData['payment_totals']['bag_box_total'] ?? 0) + 
                     ($reportData['payment_totals']['banks_transfer'] ?? 0)), 2) }}
            </td>
        </tr>
    </table>
</div>

<br><br>

Regards,<br>
<strong>{{ config('app.name') }} Automated System</strong>

</x-mail::message>