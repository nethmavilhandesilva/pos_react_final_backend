<!DOCTYPE html>
<html lang="si">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $reportTitle ?? 'Report' }}</title>
    <style>
        /* mPDF specific font declaration */
        body {
            font-family: notosanssinhala, dejavusanscondensed, sans-serif;
            font-size: 12px;
            line-height: 1.3;
        }
        
        h2, h4 { 
            text-align: center; 
            margin: 5px 0; 
            font-weight: bold;
            font-family: notosanssinhala, dejavusanscondensed, sans-serif;
        }
        
        p.report-date { 
            text-align: right; 
            margin: 5px 0 10px 0; 
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 10px; 
        }
        
        th, td { 
            border: 1px solid #000; 
            padding: 8px; 
            text-align: left; 
            vertical-align: middle; 
            font-family: notosanssinhala, dejavusanscondensed, sans-serif;
        }
        
        th { 
            background-color: #f2f2f2; 
            font-weight: bold;
        }
        
        .text-end { 
            text-align: right; 
        }
        
        tbody tr:nth-child(even) { 
            background-color: #f9f9f9; 
        }
        
        tbody tr.total-row { 
            font-weight: bold; 
            background-color: #e9ecef; 
        }
        
        tbody tr.total-row td {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        
        .company-name {
            font-family: notosanssinhala, dejavusanscondensed, sans-serif;
            font-size: 16px;
            font-weight: bold;
        }
    </style>
</head>
<body>

    <h2 class="company-name">TGK ට්‍රේඩර්ස්</h2>
    <h4>{{ $reportTitle ?? 'Report' }}</h4>

    <p class="report-date">
        වාර්තා දිනය: {{ now()->format('Y-m-d H:i') }}
    </p>

    @if(!empty($meta))
        @foreach($meta as $label => $value)
            @if($value)
                <p><strong>{{ $label }}:</strong> {{ $value }}</p>
            @endif
        @endforeach
    @endif

    <table>
        <thead>
            <tr>
                @foreach($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($reportData as $row)
                <tr @if(isset($row[0]) && ($row[0] === 'TOTAL' || $row[0] === 'මුළු එකතුව:')) class="total-row" @endif>
                    @foreach($row as $cell)
                        <td @if(is_numeric($cell) && $cell != '') class="text-end" @endif>
                            {{ $cell }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

</body>
</html>