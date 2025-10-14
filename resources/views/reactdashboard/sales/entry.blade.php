<!doctype html>
<html>
<head>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Sales Entry</title>
  @vite(['resources/css/app.css'])
  @viteReactRefresh
  @vite(['resources/js/app.jsx'])
  
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Material Icons -->
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  
  <style>
    body {
      background-color: #99ff99 !important;
      margin: 0;
      padding: 0;
      min-height: 100vh;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
    }
    
    #salesApp {
      min-height: calc(100vh - 120px);
      padding: 20px;
    }

    /* Navigation Styles */
    .top-navbar {
        background-color: #006400 !important;
        padding: 0.3rem 0;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .bottom-navbar {
        background-color: #004d00 !important;
        height: 45px;
        border-top: 2px solid #002200;
    }

    .nav-container {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        flex-wrap: nowrap;
    }

    .nav-left {
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
        gap: 10px;
    }

    .nav-right {
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
        gap: 10px;
    }

    .nav-item {
        display: flex;
        align-items: center;
    }

    .nav-link-custom {
        color: white !important;
        text-decoration: none;
        padding: 0.4rem 0.8rem !important;
        border-radius: 0.25rem;
        transition: background-color 0.2s;
        display: flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.85rem;
        white-space: nowrap;
    }

    .nav-link-custom:hover {
        background-color: rgba(255,255,255,0.15);
        color: white !important;
    }

    .nav-link-custom .material-icons {
        font-size: 18px;
    }

    /* Dropdown styles */
    .dropdown {
        position: relative;
    }

    .dropdown-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        background-color: #1a1a1a;
        min-width: 180px;
        z-index: 1000;
        border-radius: 0.375rem;
        padding: 0.5rem 0;
    }

    .dropdown:hover .dropdown-menu {
        display: block;
    }

    .dropdown-item {
        color: white;
        padding: 0.5rem 1rem;
        text-decoration: none;
        display: block;
        transition: background-color 0.2s;
    }

    .dropdown-item:hover {
        background-color: #333;
        color: white;
    }

    /* Next day info */
    .next-day-info {
        background: rgba(255,255,255,0.1);
        padding: 0.3rem 0.8rem;
        border-radius: 0.375rem;
        border: 1px solid rgba(255,255,255,0.2);
        color: white;
        font-weight: bold;
        font-size: 0.85rem;
        white-space: nowrap;
    }

    /* Ensure content doesn't get hidden behind fixed navbars */
    .main-content {
        padding-top: 50px;
        padding-bottom: 50px;
    }

    /* Make sure all text is visible */
    .text-white {
        color: white !important;
    }
  </style>
</head>
<body>
  <!-- Top Navigation Bar - Always Visible -->
  <nav class="navbar top-navbar fixed-top">
    <div class="container-fluid">
        <div class="nav-container">
            <!-- Left side navigation -->
            <div class="nav-left">
                <!-- Dashboard -->
                <div class="nav-item">
                    <a href="{{ route('dasboard.index') }}" class="nav-link-custom">
                        <span class="material-icons">dashboard</span>
                        <span>Dashboard</span>
                    </a>
                </div>

                <!-- Master Dropdown -->
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link-custom">
                        <span class="material-icons">storage</span>
                        <span>Master</span>
                    </a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="{{ route('items.index') }}">භාණ්ඩ</a>
                        <a class="dropdown-item" href="{{ route('customers.index') }}">ගනුදෙනුකරුවන්</a>
                        <a class="dropdown-item" href="{{ route('suppliers.index') }}">සැපයුම්කරුවන්</a>
                        <a class="dropdown-item" href="{{ route('customers-loans.report') }}">ණය වාර්තාව දැකීම</a>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#codeSelectModal">
                            GRN වාර්තාව
                        </a>
                    </div>
                </div>

                <!-- Income / Expense -->
                <div class="nav-item">
                    <a href="{{ route('customers-loans.index') }}" class="nav-link-custom">
                        <span class="material-icons">payments</span>
                        <span>ආදායම් / වියදම්</span>
                    </a>
                </div>
                
                <!-- GRN Button -->
                <div class="nav-item">
                    <a href="{{ route('grn.create') }}" class="nav-link-custom">
                        <span class="material-icons">receipt_long</span>
                        <span>GRN</span>
                    </a>
                </div>
                
                <!-- GRN Update -->
                <div class="nav-item">
                    <a href="{{ route('grn.updateform') }}" class="nav-link-custom">
                        <span class="material-icons">receipt_long</span>
                        <span>GRN අලුත් කිරීම</span>
                    </a>
                </div>
            </div>

            <!-- Right side items -->
            <div class="nav-right">
                <!-- Day Start Process -->
                <div class="nav-item">
                    <a href="#" class="nav-link-custom" data-bs-toggle="modal" data-bs-target="#dayStartModal">
                        <span class="material-icons">play_circle_filled</span>
                        <span>Day Start</span>
                    </a>
                </div>

                <!-- Logout -->
                <div class="nav-item">
                    <form action="{{ route('logout') }}" method="POST" style="display: inline;">
                        @csrf
                        <button type="submit" class="nav-link-custom" style="background:none; border:none; cursor:pointer;">
                            <span class="material-icons">logout</span>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>

                <!-- Next Day Info -->
                <div class="next-day-info">
                    @php
                        $lastDay = \App\Models\Setting::where('key', 'last_day_started_date')->first();
                        $nextDay = $lastDay ? \Carbon\Carbon::parse($lastDay->value)->format('Y-m-d') : \Carbon\Carbon::now()->format('Y-m-d');
                    @endphp
                    {{ $nextDay }}
                </div>
            </div>
        </div>
    </div>
  </nav>

  <!-- Main Content Area -->
  <div class="main-content">
    <div id="salesApp"></div>
  </div>

  <!-- Bottom Navigation Bar - Always Visible -->
  <nav class="navbar bottom-navbar fixed-bottom">
    <div class="container-fluid">
        <div class="nav-container">
            <div class="nav-left" style="justify-content: center; width: 100%; gap: 60px;">
                <div class="nav-item">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#itemReportModal" class="nav-link-custom">
                        එළවළු
                    </a>
                </div>
                <div class="nav-item">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#weight_modal" class="nav-link-custom">
                        බර මත
                    </a>
                </div>
                <div class="nav-item">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#grnSaleReportModal" class="nav-link-custom">
                        මිල එක්තුකරණය
                    </a>
                </div>
                <div class="nav-item">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#reportFilterModal9" class="nav-link-custom">
                        වෙනස් කිරීම
                    </a>
                </div>
                <div class="nav-item">
                    <a href="{{ route('report.grn.sales.overview') }}" target="_blank" class="nav-link-custom">
                        ඉතිරි වාර්තාව 1
                    </a>
                </div>
                <div class="nav-item">
                    <a href="{{ route('report.grn.sales.overview2') }}" target="_blank" class="nav-link-custom">
                        ඉතිරි වාර්තාව 2
                    </a>
                </div>
                <div class="nav-item">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#filterModal" class="nav-link-custom">
                        විකුණුම් වාර්තාව
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // pass server data to React via window.* variables
    window.__INITIAL_SALES__ = @json($sales->toArray());
    window.__CUSTOMERS__ = @json($customers->toArray());
    window.__ENTRIES__ = @json($entries->toArray());
    window.__ITEMS__ = @json($items->toArray());
    window.__STORE_URL__ = "{{ route('grn.store') }}";
    window.__PRINTED_SALES__ = {!! json_encode($printedSales) !!};
    window.__UNPRINTED_SALES__ = {!! json_encode($unprintedSales) !!};
    
    console.log('Navigation bars loaded - always visible');
  </script>
  {{-- In your Blade file --}}
<script>
    window.__ROUTES__ = {
        markPrinted: "{{ url('https://wday.lk/sales/mark-printed') }}",
        getLoanAmount: "{{ url('https://wday.lk/get-loan-amount') }}", 
        markAllProcessed: "{{ url('https://wday.lk/sales/mark-all-processed') }}",
        givenAmount: "{{ url('https://wday.lk/sales/:id/given-amount') }}",
        getLatestGrnEntries: "{{ url('https://wday.lk/grn-entries/latest')}}"  
    };
</script>
 <script>
    document.addEventListener('keydown', function(event) {
      if (event.key === 'F10') {
        event.preventDefault(); // prevent browser default F10 behavior
        location.reload(); // refresh the page
      }
    });
  </script>

   @include('layouts.partials.footer')
    @include('layouts.partials.report-modal')
    @include('layouts.partials.item-wisemodal')
    @include('layouts.partials.weight-modal')
    @include('layouts.partials.salecode-modal')
    @include('layouts.partials.sales-modal')
    @include('layouts.partials.salesadjustments-modal')
    @include('layouts.partials.dayStartModal')
    @include('layouts.partials.LoanReport-Modal')
    @include('layouts.partials.grn-modal')
     @include('layouts.partials.filterModal')
</body>
</html>