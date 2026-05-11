<?php

namespace App\Helpers;

use App\Models\Supplier;
use App\Models\Creditor;
use Illuminate\Support\Facades\DB;

class CreditorNumberHelper
{
    /**
     * Generate a unique creditor number
     * Format: C + sequential number (e.g., C1, C2, C3, ...)
     */
    public static function generateCreditorNumber()
    {
        // Get the maximum number from both tables
        $maxNumber = 0;
        
        // Get from suppliers table
        $supplierNumbers = Supplier::whereNotNull('Creditor_no')
            ->where('Creditor_no', 'LIKE', 'C%')
            ->pluck('Creditor_no');
            
        foreach ($supplierNumbers as $number) {
            if (preg_match('/C(\d+)/', $number, $matches)) {
                $maxNumber = max($maxNumber, (int)$matches[1]);
            }
        }
        
        // Get from creditors table
        $creditorNumbers = Creditor::whereNotNull('Creditor_no')
            ->where('Creditor_no', 'LIKE', 'C%')
            ->pluck('Creditor_no');
            
        foreach ($creditorNumbers as $number) {
            if (preg_match('/C(\d+)/', $number, $matches)) {
                $maxNumber = max($maxNumber, (int)$matches[1]);
            }
        }
        
        // Special case: if no numbers found, check if there are any records at all
        if ($maxNumber === 0) {
            // Check if any record exists with pattern C%
            $anySupplier = Supplier::where('Creditor_no', 'LIKE', 'C%')->exists();
            $anyCreditor = Creditor::where('Creditor_no', 'LIKE', 'C%')->exists();
            
            if (!$anySupplier && !$anyCreditor) {
                // Start from C1
                return 'C1';
            }
        }
        
        // Generate next number
        $nextNumber = $maxNumber + 1;
        
        return 'C' . $nextNumber;
    }
}