@extends('layouts.app')

@section('content')
    {{-- Material Icons --}}
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <style>
        body {
            background-color: #99ff99;
        }

        .custom-card {
            background-color: #006400 !important;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            padding: 24px;
        }

        .form-label {
            font-weight: bold;
            color: black;
        }

        input[readonly] {
            background-color: #e9ecef;
        }

        /* Buttons */
        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }

        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
        }

        .btn-info {
            background-color: #0dcaf0;
            border-color: #0dcaf0;
            color: #fff;
        }

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }

        .btn-sm {
            font-size: 0.75rem;
            padding: 4px 8px;
        }

        /* Table */
        .table thead th {
            background-color: #e6f0ff;
            color: #003366;
            text-align: center;
        }

        .table tbody td {
            vertical-align: middle;
            text-align: center;
        }

        .table-hover tbody tr:hover {
            background-color: #f1f5ff;
        }

        #entriesTable {
            font-size: 0.85rem;
        }

        #entriesTable th,
        #entriesTable td {
            padding: 0.3rem 0.5rem;
        }

        #entriesTable thead th {
            font-weight: 600;
        }

        /* Hidden columns initially */
        .total-grn-column,
        .total-grn-header,
        .per-kg-price-column,
        .per-kg-price-header {
            display: none;
        }

        /* Search input */
        #searchInput {
            max-width: 400px;
            margin-bottom: 1rem;
        }

        /* Context menu */
        .custom-context-menu {
            position: absolute;
            z-index: 9999;
            background-color: white;
            border: 1px solid #ccc;
            padding: 5px 0;
            box-shadow: 2px 2px 6px rgba(0, 0, 0, 0.2);
            display: none;
            width: 140px;
        }

        .custom-context-menu li {
            list-style: none;
            padding: 8px 12px;
            cursor: pointer;
        }

        .custom-context-menu li:hover {
            background-color: #f1f1f1;
        }

        /* Custom dropdown styles */
        .custom-dropdown {
            position: relative;
        }

        .dropdown-options {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ccc;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .dropdown-option {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }

        .dropdown-option:hover,
        .dropdown-option.highlighted {
            background-color: #007bff;
            color: white;
        }

        .dropdown-option:last-child {
            border-bottom: none;
        }
    </style>

    <div class="container-fluid mt-7">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="custom-card">
                    <h2 class="text-primary mb-4 text-center">📝 නව GRN ඇතුළත් කිරීම & GRN ලැයිස්තුව</h2>

                    <form method="POST" action="{{ route('grn.store2') }}">
                        @csrf
                        <div class="row g-3">
                            {{-- Item with Searchable Dropdown --}}
                            <div class="col-md-3">
                                <label for="item_search" class="form-label">අයිතම <span class="text-danger">*</span></label>
                                <div class="custom-dropdown">
                                    <input type="text" id="item_search" name="item_search"
                                        class="form-control form-control-sm @error('item_code') is-invalid @enderror"
                                        placeholder="Type to search items..." autocomplete="off" required
                                        style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                                    <input type="hidden" id="item_code" name="item_code" value="{{ old('item_code') }}">
                                    <input type="hidden" id="item_type" name="item_type" value="{{ old('item_type') }}">
                                    <div class="dropdown-options" id="item_options">
                                        @foreach($items as $item)
                                            <div class="dropdown-option" data-value="{{ $item->no }}"
                                                data-type="{{ $item->type }}"
                                                data-fulltext="{{ $item->no }} - {{ $item->type }}">
                                                {{ $item->no }} - {{ $item->type }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                @error('item_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            {{-- Supplier --}}
                            <div class="col-md-3">
                                <label for="supplier_name" class="form-label">සැපයුම්කරු <span
                                        class="text-danger">*</span></label>
                                <input list="suppliers_list" id="supplier_name" name="supplier_name"
                                    class="form-control form-control-sm @error('supplier_name') is-invalid @enderror"
                                    value="{{ old('supplier_name') }}" required
                                    oninput="this.value = this.value.toUpperCase();" style="text-transform: uppercase;">
                                <datalist id="suppliers_list">
                                    @foreach($suppliers as $supplier)
                                        <option value="{{ $supplier->code }}" data-code="{{ $supplier->code }}">
                                    @endforeach
                                </datalist>
                                <input type="hidden" id="supplier_code_input" name="supplier_code">
                                @error('supplier_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            {{-- GRN No --}}
                            <div class="col-md-3">
                                <label for="grn_no" class="form-label">GRN අංකය</label>
                                <input type="text" id="grn_no" name="grn_no" class="form-control form-control-sm"
                                    value="{{ old('grn_no') }}">
                            </div>

                            {{-- Warehouse --}}
                            <div class="col-md-3">
                                <label for="warehouse_no" class="form-label">ගබඩා අංකය</label>
                                <input type="text" id="warehouse_no" name="warehouse_no"
                                    class="form-control form-control-sm" value="{{ old('warehouse_no') }}">
                            </div>

                            {{-- Packs --}}
                            <div class="col-md-2">
                                <label for="packs" class="form-label">පැක්‌ <span class="text-danger">*</span></label>
                                <input type="number" id="packs" name="packs" class="form-control form-control-sm"
                                    value="{{ old('packs') }}" min="1">
                            </div>

                            {{-- Weight --}}
                            <div class="col-md-2">
                                <label for="weight" class="form-label">බර (kg) <span class="text-danger">*</span></label>
                                <input type="number" id="weight" name="weight" class="form-control form-control-sm"
                                    value="{{ old('weight') }}" step="0.01" min="0.01">
                            </div>

                            {{-- Total GRN --}}
                            <div class="col-md-3">
                                <label for="total_grn" class="form-label">GRN මුළු එකතුව</label>
                                <input type="number" id="total_grn" name="total_grn" class="form-control form-control-sm"
                                    value="{{ old('total_grn') }}" step="0.01">
                            </div>

                            {{-- Per KG --}}
                            <div class="col-md-3">
                                <label for="per_kg_price" class="form-label">Per KG Price</label>
                                <input type="number" id="per_kg_price" name="per_kg_price"
                                    class="form-control form-control-sm" value="{{ old('per_kg_price') }}" step="0.01">
                            </div>

                            {{-- Transaction Date --}}
                            <div class="col-md-2">
                                <label for="txn_date" class="form-label">දිනය <span class="text-danger">*</span></label>
                                <input type="date" id="txn_date" name="txn_date" class="form-control form-control-sm"
                                    value="{{ old('txn_date', date('Y-m-d')) }}">
                            </div>

                            <div class="d-flex gap-2 align-items-end">
                                <div class="d-flex justify-content-center gap-2 mb-3 flex-wrap">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="material-icons align-middle me-1">check_circle</i> GRN ADD
                                    </button>

                                    <a href="{{ route('grn.index') }}" class="btn btn-secondary btn-sm">
                                        <i class="material-icons align-middle me-1">cancel</i> GRN REMOVE
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>

                    {{-- Rest of your existing code (table, modals, etc.) --}}
                    <hr class="my-4">
                    <div class="mb-3">
                        <label for="list_password" class="form-label">මුරපදය (View hidden columns)</label>
                        <input type="password" id="list_password" class="form-control form-control-sm">
                    </div>
                    <input type="text" id="searchInput" class="form-control form-control-sm mb-3"
                        placeholder="Search by Code, Supplier, Item Code, or Name">

                    @if($entries->isEmpty())
                        <div class="alert alert-info text-center" role="alert">
                            කිසිඳු GRN ඇතුළත් කර නොමැත.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover align-middle table-sm"
                                id="entriesTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>කේතය</th>
                                        <th class="d-none">සැපයුම්කරුගේ කේතය</th>
                                        <th class="d-none">අයිතම කේතය</th>
                                        <th>අයිතම නාමය</th>
                                        <th>පැක්‌</th>
                                        <th>බර (kg)</th>
                                        <th>ගනුදෙනු දිනය</th>
                                        <th>GRN අංකය</th>
                                        <th class="total-grn-header">GRN මුළු එකතුව</th>
                                        <th class="per-kg-price-header">Per KG Price</th>
                                        <th>මෙහෙයුම්</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($entries as $entry)
                                        <tr class="grn-row" data-entry-id="{{ $entry->id }}">
                                            <td class="search-code">{{ $entry->code }}</td>
                                            <td class="search-supplier-code d-none">{{ $entry->supplier_code }}</td>
                                            <td class="search-item-code d-none">{{ $entry->item_code }}</td>
                                            <td class="search-item-name">{{ $entry->item_name }}</td>
                                            <td>{{ $entry->original_packs }}</td>
                                            <td>{{ $entry->original_weight }}</td>
                                            <td>{{ $entry->txn_date }}</td>
                                            <td>{{ $entry->grn_no }}</td>
                                            <td class="total-grn-column">{{ $entry->total_grn }}</td>
                                            <td class="per-kg-price-column">{{ $entry->PerKGPrice }}</td>
                                            <td>
                                                <a href="{{ route('grn.edit', $entry->id) }}" class="btn btn-sm btn-info me-1"><i
                                                        class="material-icons">edit</i></a>
                                                <button type="button" class="btn btn-sm btn-danger delete-btn"
                                                    data-entry-id="{{ $entry->id }}">
                                                    <i class="material-icons">delete</i>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- Your existing modals and context menu --}}
                    <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Delete GRN Entry</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    Are you sure you want to delete this GRN entry? This action cannot be undone.
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <form id="deleteForm" method="POST" action="">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger">Yes, Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <ul class="custom-context-menu" id="contextMenu">
                        <li id="hideOption">Hide</li>
                        <li id="unhideOption">Don't Hide</li>
                    </ul>

                   {{-- ===================== Scripts ===================== --}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Auto-calculate Per KG Price
        const totalGrnInput = document.getElementById('total_grn');
        const weightInput = document.getElementById('weight');
        const perKgPriceInput = document.getElementById('per_kg_price');

        function calculatePerKgPrice() {
            const totalGrn = parseFloat(totalGrnInput.value) || 0;
            const weight = parseFloat(weightInput.value) || 0;

            if (totalGrn > 0 && weight > 0) {
                const perKgPrice = (totalGrn / weight).toFixed(2);
                perKgPriceInput.value = perKgPrice;
            } else {
                perKgPriceInput.value = '';
            }
        }

        // Calculate when either total GRN or weight changes
        totalGrnInput.addEventListener('input', calculatePerKgPrice);
        weightInput.addEventListener('input', calculatePerKgPrice);

        // Auto-focus on item_search field after page load
        const itemSearch = document.getElementById('item_search');
        if (itemSearch) {
            // Small timeout to ensure everything is loaded
            setTimeout(() => {
                itemSearch.focus();
            }, 100);
        }

        // Item Search Dropdown Functionality
        const itemCode = document.getElementById('item_code');
        const itemType = document.getElementById('item_type');
        const itemOptions = document.getElementById('item_options');
        const options = itemOptions.querySelectorAll('.dropdown-option');
        let currentHighlight = -1;
        let isOptionSelected = false;

        // Filter options based on search input - ONLY FIRST LETTER MATCH
        function filterOptions() {
            const searchTerm = itemSearch.value.toLowerCase();
            let hasVisibleOptions = false;
            let firstMatchIndex = -1;

            options.forEach((option, index) => {
                const itemNo = option.dataset.value.toLowerCase();
                const fullText = option.dataset.fulltext.toLowerCase();

                // Show options ONLY where the first letter exactly matches
                // Remove the fullText.includes(searchTerm) part to only match by first letter
                const shouldShow = itemNo.startsWith(searchTerm) || searchTerm === '';

                option.style.display = shouldShow ? 'block' : 'none';

                if (shouldShow && firstMatchIndex === -1) {
                    firstMatchIndex = index;
                }
                if (shouldShow) hasVisibleOptions = true;
            });

            // Show/hide dropdown
            itemOptions.style.display = hasVisibleOptions ? 'block' : 'none';

            // Reset highlight
            currentHighlight = -1;
            removeHighlights();

            // Auto-highlight first option
            if (firstMatchIndex !== -1 && !isOptionSelected) {
                highlightOption(firstMatchIndex);
            }
        }

        // Highlight an option
        function highlightOption(index) {
            removeHighlights();
            if (options[index] && options[index].style.display === 'block') {
                options[index].classList.add('highlighted');
                currentHighlight = index;
            }
        }

        // Remove all highlights
        function removeHighlights() {
            options.forEach(option => {
                option.classList.remove('highlighted');
            });
        }

        // Select an option
        function selectOption(option) {
            itemSearch.value = option.dataset.fulltext;
            itemCode.value = option.dataset.value;
            itemType.value = option.dataset.type;
            itemOptions.style.display = 'none';
            currentHighlight = -1;
            isOptionSelected = true;
        }

        // Clear selection and allow editing
        function clearSelection() {
            itemCode.value = '';
            itemType.value = '';
            isOptionSelected = false;
            // Don't clear the search field - let user edit what's there
        }

        // Event Listeners for Item Search
        itemSearch.addEventListener('input', function () {
            // Clear selection when user starts typing again
            if (isOptionSelected) {
                clearSelection();
            }
            filterOptions();
        });

        itemSearch.addEventListener('focus', function () {
            filterOptions();
        });

        // Allow backspace and other keys to work normally
        itemSearch.addEventListener('keydown', function (e) {
            // If backspace is pressed and there's a selection, clear it first
            if (e.key === 'Backspace' && isOptionSelected) {
                clearSelection();
                // Don't prevent default - allow backspace to work normally
                return;
            }

            // If delete key is pressed and there's a selection, clear it first
            if (e.key === 'Delete' && isOptionSelected) {
                clearSelection();
                // Don't prevent default - allow delete to work normally
                return;
            }

            const visibleOptions = Array.from(options).filter(opt =>
                opt.style.display === 'block'
            );

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                let nextHighlight = currentHighlight + 1;
                while (nextHighlight < options.length &&
                    options[nextHighlight].style.display !== 'block') {
                    nextHighlight++;
                }
                if (nextHighlight < options.length) {
                    highlightOption(nextHighlight);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                let prevHighlight = currentHighlight - 1;
                while (prevHighlight >= 0 &&
                    options[prevHighlight].style.display !== 'block') {
                    prevHighlight--;
                }
                if (prevHighlight >= 0) {
                    highlightOption(prevHighlight);
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentHighlight !== -1 &&
                    options[currentHighlight].style.display === 'block') {
                    selectOption(options[currentHighlight]);
                    // Move to next field after selection
                    document.getElementById('supplier_name').focus();
                } else if (itemSearch.value.trim() !== '') {
                    // Try to find exact match
                    const exactMatch = Array.from(options).find(opt =>
                        opt.dataset.fulltext === itemSearch.value ||
                        opt.dataset.value === itemSearch.value
                    );
                    if (exactMatch) {
                        selectOption(exactMatch);
                        document.getElementById('supplier_name').focus();
                    } else {
                        // If no exact match and user presses enter, just move to next field
                        document.getElementById('supplier_name').focus();
                    }
                }
            } else if (e.key === 'Escape') {
                itemOptions.style.display = 'none';
                currentHighlight = -1;
            }
        });

        // Click on option to select
        options.forEach(option => {
            option.addEventListener('click', function () {
                selectOption(this);
                document.getElementById('supplier_name').focus();
            });
        });

        // Click outside to close dropdown
        document.addEventListener('click', function (e) {
            if (!itemSearch.contains(e.target) && !itemOptions.contains(e.target)) {
                itemOptions.style.display = 'none';
                currentHighlight = -1;
            }
        });

        // Double click or select all to allow editing
        itemSearch.addEventListener('dblclick', function () {
            if (isOptionSelected) {
                clearSelection();
                // Select all text for easy replacement
                this.select();
            }
        });

        // Click to select all text when field has a selected value
        itemSearch.addEventListener('click', function () {
            if (isOptionSelected) {
                this.select();
            }
        });

        const listPasswordField = document.getElementById('list_password');
        const totalGrnCells = document.querySelectorAll('.total-grn-column');
        const totalGrnHeader = document.querySelector('.total-grn-header');
        const perKgCells = document.querySelectorAll('.per-kg-price-column');
        const perKgHeader = document.querySelector('.per-kg-price-header');

        function toggleHiddenColumns(show) {
            const displayStyle = show ? 'table-cell' : 'none';
            if (totalGrnHeader) totalGrnHeader.style.display = displayStyle;
            if (perKgHeader) perKgHeader.style.display = displayStyle;
            totalGrnCells.forEach(cell => cell.style.display = displayStyle);
            perKgCells.forEach(cell => cell.style.display = displayStyle);
        }

        listPasswordField.addEventListener('input', function () {
            const isCorrect = this.value === 'nethma123';
            toggleHiddenColumns(isCorrect);
            if (isCorrect) {
                this.style.backgroundColor = '#d4edda';
                this.style.borderColor = '#28a745';
            } else {
                this.style.backgroundColor = '#f8d7da';
                this.style.borderColor = '#dc3545';
            }
            if (this.value === '') {
                this.style.backgroundColor = '';
                this.style.borderColor = '';
            }
        });

        // ===================== Search filter =====================
        const searchInput = document.getElementById('searchInput');
        const rows = document.querySelectorAll('#entriesTable tbody tr');
        searchInput.addEventListener('keyup', function () {
            const filter = this.value.toLowerCase();
            rows.forEach(row => {
                const code = row.querySelector('.search-code')?.textContent.toLowerCase() || '';
                const supplierCode = row.querySelector('.search-supplier-code')?.textContent.toLowerCase() || '';
                const itemCode = row.querySelector('.search-item-code')?.textContent.toLowerCase() || '';
                const itemName = row.querySelector('.search-item-name')?.textContent.toLowerCase() || '';
                row.style.display = (code.includes(filter) || supplierCode.includes(filter) || itemCode.includes(filter) || itemName.includes(filter)) ? '' : 'none';
            });
        });

        // Delete modal functionality
        const deleteButtons = document.querySelectorAll('.delete-btn');
        const deleteForm = document.getElementById('deleteForm');
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));

        deleteButtons.forEach(button => {
            button.addEventListener('click', function () {
                const entryId = this.dataset.entryId;
                deleteForm.action = `/grn/${entryId}`;
                deleteModal.show();
            });
        });

        // Form navigation with Enter key
        const form = document.querySelector('form[action="{{ route('grn.store2') }}"]');
        const fields = [
            'item_search',
            'supplier_name',
            'grn_no',
            'warehouse_no',
            'packs',
            'weight',
            'total_grn',
            'per_kg_price',
            'txn_date'
        ];

        fields.forEach((fieldId, index) => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();

                        if (fieldId === 'per_kg_price') {
                            form.submit();
                            return;
                        }

                        const nextFieldId = fields[index + 1];
                        if (nextFieldId) {
                            const nextField = document.getElementById(nextFieldId);
                            if (nextField) {
                                nextField.focus();
                            }
                        }
                    }
                });
            }
        });
    });
</script>
@endsection