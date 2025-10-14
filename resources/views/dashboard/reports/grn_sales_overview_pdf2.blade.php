<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>ඉතිරි වාර්තාව</title>
    <style>
        body { 
            font-family: DejaVu Sans, Arial Unicode MS, Arial, sans-serif; 
            margin: 0; 
            padding: 0; 
            font-size: 12px;
        }
        .header { 
            text-align: center; 
            margin-bottom: 15px;
        }
        h1 { 
            margin: 10px 0 5px 0;
            font-size: 16px;
            font-weight: bold;
        }
        .report-date {
            font-size: 11px;
            margin-bottom: 10px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 10px; 
            font-size: 9px;
        }
        th, td { 
            border: 1px solid #000; 
            padding: 3px; 
            text-align: center; 
        }
        th { 
            background-color: #f2f2f2; 
            font-weight: bold; 
            font-size: 8px;
        }
        .total-row { 
            background-color: #e6e6e6; 
            font-weight: bold; 
        }
        .item-name { 
            text-align: left; 
            padding-left: 5px;
        }
        
        .container {
            width: 100%;
        }
        
        .no-data {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ඉතිරි වාර්තාව</h1>
            <div class="report-date">
                {{ \Carbon\Carbon::now()->format('Y-m-d') }}
            </div>
        </div>
        
        @if(isset($reportData) && count($reportData) > 0)
        <table>
            <thead>
                <tr>
                    <th rowspan="2" width="25%">වර්ගය</th>
                    <th colspan="2" width="25%">මිලදී ගැනීම</th>
                    <th colspan="2" width="25%">විකුණුම්</th>
                    <th colspan="2" width="25%">ඉතිරි</th>
                </tr>
                <tr>
                    <th width="12.5%">බර</th>
                    <th width="12.5%">මලු</th>
                    <th width="12.5%">බර</th>
                    <th width="12.5%">මලු</th>
                    <th width="12.5%">බර</th>
                    <th width="12.5%">මලු</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $grandTotalOriginalPacks = 0;
                    $grandTotalOriginalWeight = 0;
                    $grandTotalSoldPacks = 0;
                    $grandTotalSoldWeight = 0;
                    $grandTotalRemainingPacks = 0;
                    $grandTotalRemainingWeight = 0;
                @endphp
                @foreach($reportData as $data)
                    @php
                        $originalPacks = floatval($data['original_packs'] ?? 0);
                        $originalWeight = floatval($data['original_weight'] ?? 0);
                        $soldPacks = floatval($data['sold_packs'] ?? 0);
                        $soldWeight = floatval($data['sold_weight'] ?? 0);
                        $remainingPacks = floatval($data['remaining_packs'] ?? 0);
                        $remainingWeight = floatval($data['remaining_weight'] ?? 0);

                        $grandTotalOriginalPacks += $originalPacks;
                        $grandTotalOriginalWeight += $originalWeight;
                        $grandTotalSoldPacks += $soldPacks;
                        $grandTotalSoldWeight += $soldWeight;
                        $grandTotalRemainingPacks += $remainingPacks;
                        $grandTotalRemainingWeight += $remainingWeight;
                    @endphp
                    <tr>
                        <td class="item-name">{{ $data['item_name'] ?? 'N/A' }}</td>
                        <td>{{ number_format($originalWeight, 2) }}</td>
                        <td>{{ number_format($originalPacks) }}</td>
                        <td>{{ number_format($soldWeight, 2) }}</td>
                        <td>{{ number_format($soldPacks) }}</td>
                        <td>{{ number_format($remainingWeight, 2) }}</td>
                        <td>{{ number_format($remainingPacks) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td class="item-name"><strong>සමස්ත එකතුව:</strong></td>
                    <td><strong>{{ number_format($grandTotalOriginalWeight, 2) }}</strong></td>
                    <td><strong>{{ number_format($grandTotalOriginalPacks) }}</strong></td>
                    <td><strong>{{ number_format($grandTotalSoldWeight, 2) }}</strong></td>
                    <td><strong>{{ number_format($grandTotalSoldPacks) }}</strong></td>
                    <td><strong>{{ number_format($grandTotalRemainingWeight, 2) }}</strong></td>
                    <td><strong>{{ number_format($grandTotalRemainingPacks) }}</strong></td>
                </tr>
            </tbody>
        </table>
        @else
        <div class="no-data">
            දත්ත නොමැත.
        </div>
        @endif
    </div>
</body>
</html>