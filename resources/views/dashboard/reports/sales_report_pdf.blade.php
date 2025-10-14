<!DOCTYPE html>
<html>
<head>
    <title>Sales Report</title>
    <style>
        body { font-family: 'notosanssinhala', sans-serif; }
        h1 { text-align: center; margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 10px; }
        th, td { border: 1px solid #000; padding: 4px; text-align: center; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .grand-total { text-align: right; margin-top: 15px; font-size: 12px; font-weight: bold; }
        .summary-info { margin-bottom: 10px; font-size: 10px; }
        .customer-section { margin-bottom: 20px; page-break-inside: avoid; }
        .customer-header { background-color: #e0e0e0; padding: 6px; margin-bottom: 5px; border-left: 4px solid #333; display: flex; justify-content: space-between; font-size: 10px; }
        .customer-total { text-align: right; margin-top: 5px; font-weight: bold; background-color: #f5f5f5; padding: 3px; font-size: 10px; }
        .highlight-red { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Sales Report</h1>

    <!-- Summary Information -->
    <div class="summary-info">
        @if(request()->filled('start_date') && request()->filled('end_date'))
            <p><strong>Period:</strong> {{ request()->start_date }} to {{ request()->end_date }}</p>
        @elseif(request()->filled('start_date'))
            <p><strong>From:</strong> {{ request()->start_date }}</p>
        @elseif(request()->filled('end_date'))
            <p><strong>Until:</strong> {{ request()->end_date }}</p>
        @endif
        @if(request()->filled('customer_code')) <p><strong>Customer Code:</strong> {{ request()->customer_code }}</p> @endif
        @if(request()->filled('supplier_code')) <p><strong>Supplier Code:</strong> {{ request()->supplier_code }}</p> @endif
        @if(request()->filled('item_code')) <p><strong>Item Code:</strong> {{ request()->item_code }}</p> @endif
        @if(request()->filled('bill_no')) <p><strong>Bill No:</strong> {{ request()->bill_no }}</p> @endif
    </div>

    @php
        $grandTotal = 0;
        $groupedData = $salesData->groupBy(function($sale) {
            return $sale->bill_no ?: $sale->customer_code;
        });
    @endphp

    @if($salesData->isNotEmpty())
        @foreach($groupedData as $groupKey => $sales)
            @php
                $isBill = !empty($sales->first()->bill_no);
                $billTotal = $sales->sum(function($sale) { return $sale->weight * $sale->price_per_kg; });
                $billTotal2 = $sales->sum('total');
                $grandTotal += $billTotal2;
                $firstPrinted = $sales->first()->FirstTimeBillPrintedOn ?? null;
                $reprinted = $sales->first()->BillReprintAfterchanges ?? null;
            @endphp

            <div class="customer-section">
                <div class="customer-header">
                    <div>
                        @if($isBill)
                            <strong>Bill No:</strong> {{ $sales->first()->bill_no }} | 
                        @endif
                        <strong>Customer Code:</strong> {{ $sales->first()->customer_code ?? '-' }}
                    </div>
                    <div>
                        @if($firstPrinted) First Printed: {{ \Carbon\Carbon::parse($firstPrinted)->format('Y-m-d') }} @endif
                        @if($reprinted) | Reprinted: {{ \Carbon\Carbon::parse($reprinted)->format('Y-m-d') }} @endif
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Item Name</th>
                            <th>Weight</th>
                            <th>Price/Kg</th>
                            <th>Packs</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sales as $sale)
                            @php
                                $grn = \App\Models\GrnEntry::where('code', trim($sale->code))->first();
                                $grnPrice = $grn ? (float) $grn->PerKGPrice : null;
                                $isLower = $grnPrice !== null && $sale->price_per_kg < $grnPrice;
                            @endphp
                            <tr>
                                <td>{{ $sale->code }}</td>
                                <td class="text-left">{{ $sale->item_name }}</td>
                                <td>{{ number_format($sale->weight, 2) }}</td>
                                <td class="{{ $isLower ? 'highlight-red' : '' }}">{{ number_format($sale->price_per_kg, 2) }}</td>
                                <td>{{ $sale->packs }}</td>
                                <td>{{ number_format($sale->weight * $sale->price_per_kg, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" style="text-align:right; font-weight:bold;">Total:</td>
                            <td>{{ number_format($billTotal, 2) }}</td>
                        </tr>
                        <tr>
                            <td colspan="5" style="text-align:right; font-weight:bold;">Total with Packs:</td>
                            <td>{{ number_format($billTotal2, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endforeach

        <div class="grand-total">
            Grand Total: {{ number_format($grandTotal, 2) }}
        </div>
    @else
        <p style="text-align:center; color:#888;">No sales records found.</p>
    @endif
</body>
</html>
