<?php

namespace App\Helpers;

use App\Models\Customer;
use App\Models\Debtor;
use Illuminate\Support\Facades\DB;

class DebtorNumberHelper
{
    /**
     * Generate a unique debtor number
     * Format: D + sequential number (e.g., D1, D2, D3, ...)
     */
    public static function generateDebtorNumber()
    {
        // Get the highest debtor number from both tables
        $lastCustomerNumber = Customer::whereNotNull('Debtor_no')
            ->where('Debtor_no', 'like', 'D%')
            ->orderBy('id', 'desc')
            ->value('Debtor_no');
        
        $lastDebtorNumber = Debtor::whereNotNull('Debtor_no')
            ->where('Debtor_no', 'like', 'D%')
            ->orderBy('id', 'desc')
            ->value('Debtor_no');
        
        // Extract the numeric part
        $maxNumber = 0;
        
        if ($lastCustomerNumber && preg_match('/D(\d+)/', $lastCustomerNumber, $matches)) {
            $maxNumber = max($maxNumber, (int)$matches[1]);
        }
        
        if ($lastDebtorNumber && preg_match('/D(\d+)/', $lastDebtorNumber, $matches)) {
            $maxNumber = max($maxNumber, (int)$matches[1]);
        }
        
        // Also check database for any existing pattern
        $allNumbers = DB::table('customers')
            ->whereNotNull('Debtor_no')
            ->where('Debtor_no', 'like', 'D%')
            ->pluck('Debtor_no')
            ->merge(
                DB::table('debtors')
                    ->whereNotNull('Debtor_no')
                    ->where('Debtor_no', 'like', 'D%')
                    ->pluck('Debtor_no')
            );
        
        foreach ($allNumbers as $number) {
            if (preg_match('/D(\d+)/', $number, $matches)) {
                $maxNumber = max($maxNumber, (int)$matches[1]);
            }
        }
        
        // Generate next number
        $nextNumber = $maxNumber + 1;
        
        return 'D' . $nextNumber;
    }
}