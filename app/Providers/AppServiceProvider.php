<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema; 
use Illuminate\Support\Facades\Route; // <-- ADDED: Necessary for defining route groups

use Illuminate\Support\Facades\View;
use App\Models\GrnEntry;
use App\Models\Item;
use App\Models\Customer;
use App\Models\Supplier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ✅ Set default string length for older MySQL versions
        Schema::defaultStringLength(191);

        // ✅ Automatically share 'entries' with specific modal views
        View::composer([
            'layouts.partials.report-modal',
            'layouts.partials.weight-modal',
            'layouts.partials.salecode-modal',
            'layouts.partials.item-wisemodal',
            'layouts.partials.salesadjustments-modal',
            'layouts.partials.grn-modal',
            'layouts.partials.filterModal'  // Keep if this modal still exists and uses 'entries'
        ], function ($view) {
            $view->with('entries', GrnEntry::where('is_hidden', 0)->get());
        });

        // ✅ NEW: Share filter options specifically with layouts.partials.report-modal
        View::composer(
            ['layouts.partials.report-modal', 'layouts.partials.filterModal'], 
            function ($view) {
                $view->with('items', Item::all());
                $view->with('customers', Customer::all());
                $view->with('suppliers', Supplier::all());
            }
        );

        // ✅ NEW: Share filter options specifically with itemReportModal.blade.php
        View::composer('layouts.partials.itemReportModal', function ($view) {
            $view->with('items', Item::all());
            $view->with('customers', Customer::all());
            $view->with('suppliers', Supplier::all());
        });

        // Your existing composer for sales-modal
        View::composer('layouts.partials.sales-modal', function ($view) {
            $view->with('items', Item::all());
            $view->with('customers', Customer::all());
            $view->with('suppliers', Supplier::all());
        });

        View::composer('layouts.partials.LoanReport-Modal', function ($view) {
            $view->with('customers', Customer::all());
        });

        View::composer('layouts.partials.grn-modal', function ($view) {
            // Fetch unique 'code' values from the GrnEntry model
            $codes = GrnEntry::select('code')->distinct()->pluck('code');

            // Share the 'codes' variable with the view
            $view->with('codes', $codes);

            // Your existing line (if you still need it)
            $view->with('entries', GrnEntry::where('is_hidden', 0)->get());
        });

        // ------------------------------------------------------------------
        // CRITICAL FIX: MANUALLY LOAD API ROUTES
        // This ensures routes/api.php is loaded with the '/api' prefix and 
        // the 'api' middleware (which disables CSRF protection).
        // ------------------------------------------------------------------
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/api.php'));
    }
}
