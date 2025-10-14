@extends('layouts.app')

@section('content')
    <style>
        body {
            background-color: #99ff99 !important;
        }

        .custom-card {
            background-color: #004d00 !important;
            color: #fff;
            padding: 25px;
            border-radius: 10px;
        }

        .form-control,
        .form-select {
            padding: 0.15rem 0.4rem !important;
            font-size: 0.75rem !important;
            border: 1px solid black !important;
            color: black !important;
            font-weight: bold !important;
            background-color: white !important;
        }

        .table td,
        .table th {
            padding: 0.3rem;
            font-size: 0.875rem;
        }

        label {
            font-weight: 500;
            margin-bottom: 0.2rem;
            color: #000;
        }

        .table th {
            background-color: #006600;
            color: white;
        }

        h3,
        h4 {
            color: #ffffff;
        }

        .bg-custom-dark {
            background-color: #004d00 !important;
            color: #fff;

        }


        .text-form-label {
            color: #fff !important;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.2rem;
        }

        .form-control-sm,
        .form-select-sm {
            padding: 0.15rem 0.4rem !important;
            font-size: 0.75rem !important;
            border: 1px solid black !important;
            color: black !important;
            font-weight: bold !important;
            background-color: white !important;
        }

        .bg-custom-dark strong {
            color: #fff !important;
        }

        .btn-green-submit {
            background-color: #28a745;
            color: #fff;
        }

        .btn-green-submit:hover {
            background-color: #218838;
            color: #fff;
        }
    </style>

    <div class="container my-4">
        <div class="custom-card">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            {{-- Loan Entry Form --}}
            <form method="POST" action="{{ route('customers-loans.store') }}" id="loanForm"
                class="p-3 border border-2 border-dark rounded bg-custom-dark">
                @csrf
                {{-- Laravel method spoofing --}}
                <input type="hidden" name="_method" id="methodField" value="POST">
                <input type="hidden" name="loan_id" id="loan_id">

                <div class="row gy-2">
                    <div class="col-md-8">
                        <label class="me-3" style="color: white;">
                            <input type="radio" name="loan_type" value="old" checked>
                            වෙළෙන්දාගේ ලාද පරණ නය
                        </label>

                        <label class="me-3" style="color: white;">
                            <input type="radio" name="loan_type" value="today">
                            වෙළෙන්දාගේ අද දින නය ගැනීම
                        </label>

                        <label class="me-3" style="color: white;">
                            <input type="radio" name="loan_type" value="ingoing">
                            වෙනත් ලාභීම/ආදායම්
                        </label>

                        <label class="me-3" style="color: white;">
                            <input type="radio" name="loan_type" value="outgoing">
                            වි‍යදම්
                        </label>

                        {{-- NEW: GRN Damages Radio Button --}}
                        <label style="color: white;">
                            <input type="radio" name="loan_type" value="grn_damage">
                            GRN Damages
                        </label>
                        <label class="me-3" style="color: white;">
                            <input type="radio" name="loan_type" value="returns">
                            Returns
                        </label>

                    </div>

                    <div class="col-md-4" id="settlingWaySection">
                        <label class="text-form-label" style="color: white;"><strong>Settling Way:</strong></label><br>
                        <label class="me-3" style="color: white;">
                            <input type="radio" name="settling_way" value="cash" checked>
                            Cash
                        </label>
                        <label style="color: white;">
                            <input type="radio" name="settling_way" value="cheque">
                            Cheque
                        </label>
                    </div>

                    <div class="col-md-4" id="customer_section">
                        <label for="customer_id" class="text-form-label">ගෙණුම්කරු</label>
                        <select class="form-select form-select-sm" id="customer_id" name="customer_id" required>
                            <option value="">-- Select Customer --</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" data-credit-limit="{{ $customer->credit_limit }}"
                                    data-short-name="{{ $customer->short_name }}">
                                    {{ $customer->short_name }} - {{ $customer->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3" id="bill_no_section">
                        <label for="bill_no" class="text-form-label">Bill No</label>
                        <input type="text" class="form-control form-control-sm" name="bill_no">
                    </div>

                    <div id="loan-details-row" class="row gx-2">
                        <div class="col-md-2" id="amount_section">
                            <label for="amount" class="text-form-label">මුදල</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="amount" required>
                            <span id="creditLimitMessage" class="text-danger"
                                style="font-weight: bold; font-size: 0.8rem;"></span>
                        </div>
                        <div class="col-md-5" id="description_section">
                            <label for="description" class="text-form-label">විස්තරය</label>
                            <input type="text" class="form-control form-control-sm" name="description" id="description"
                                required>
                            <span id="totalAmountDisplay" class="text-white-50"
                                style="font-weight: bold; font-size: 0.9rem;"></span>
                        </div>
                    </div>

                    <div id="chequeFields" class="col-md-5 ms-auto d-none">
                        <div class="border rounded p-2 bg-light" style="border-color: #006600 !important;">
                            <h6 class="text-success fw-bold mb-2" style="border-bottom: 1px solid #006600;">Cheque Details
                            </h6>
                            <div class="row g-2">
                                <div class="col-4">
                                    <label for="cheque_date" class="form-label mb-1">Cheque Date</label>
                                    <input type="date" class="form-control form-control-sm" name="cheque_date"
                                        value="{{ date('Y-m-d') }}" disabled>
                                </div>
                                <div class="col-4">
                                    <label for="cheque_no" class="form-label mb-1">Cheque No</label>
                                    <input type="text" class="form-control form-control-sm" name="cheque_no" disabled>
                                </div>
                                <div class="col-4">
                                    <label for="bank" class="form-label mb-1">Bank</label>
                                    <input type="text" class="form-control form-control-sm" name="bank" id="bank" disabled>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- NEW: Wasted Details Section --}}
                    <div id="wastedFields" class="col-md-7 ms-auto d-none">
                        <div class="border rounded p-2 bg-light" style="border-color: #006600 !important;">
                            <h6 class="text-success fw-bold mb-2" style="border-bottom: 1px solid #006600;">Wasted Details
                            </h6>
                            <div class="row g-2">
                                <div class="col-4">
                                    <label for="wasted_code" class="form-label mb-1">Code</label>
                                    <select class="form-select form-select-sm" name="wasted_code" id="wasted_code" disabled>
                                        <option value="">-- Select Code --</option>
                                        @foreach($grnCodes as $code)
                                            <option value="{{ $code }}">{{ $code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label for="wasted_packs" class="form-label mb-1">Wasted Packs</label>
                                    <input type="number" step="1" class="form-control form-control-sm" name="wasted_packs"
                                        id="wasted_packs" disabled>
                                </div>
                                <div class="col-4">
                                    <label for="wasted_weight" class="form-label mb-1">Wasted Weight</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm"
                                        name="wasted_weight" id="wasted_weight" disabled>
                                </div>
                            </div>
                        </div>
                    </div>
                    {{-- NEW: Returns Section --}}
                    <div id="returnsFields" class="col-md-12 d-none">
                        <div class="border rounded p-2 bg-light" style="border-color: #006600 !important;">
                            <h6 class="text-success fw-bold mb-2" style="border-bottom: 1px solid #006600;">Returns Details
                            </h6>
                            <div class="row g-2">
                                <div class="col-2">
                                    <label for="return_grn_code" class="form-label mb-1">GRN Code</label>
                                    <select class="form-select form-select-sm" name="return_grn_code" id="return_grn_code">
                                        <option value="">-- Select GRN Code --</option>
                                        @foreach($grnCodes as $code)
                                            <option value="{{ $code }}">{{ $code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-2">
                                    <label for="return_item_code" class="form-label mb-1">Item Code</label>
                                    <input type="text" class="form-control form-control-sm" name="return_item_code"
                                        id="return_item_code" readonly>
                                </div>
                                <div class="col-2">
                                    <label for="return_bill_no" class="form-label mb-1">Bill No</label>
                                    <select class="form-select form-select-sm" name="return_bill_no" id="return_bill_no">
                                        <option value="">-- Select Bill --</option>
                                        @foreach(\App\Models\Sale::pluck('bill_no') as $bill)
                                            <option value="{{ $bill }}">{{ $bill }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-2">
                                    <label for="return_weight" class="form-label mb-1">Weight</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm"
                                        name="return_weight" id="return_weight">
                                </div>
                                <div class="col-2">
                                    <label for="return_packs" class="form-label mb-1">Packs</label>
                                    <input type="number" step="1" class="form-control form-control-sm" name="return_packs"
                                        id="return_packs">
                                </div>
                                <div class="col-2">
                                    <label for="return_reason" class="form-label mb-1">Reason</label>
                                    <input type="text" class="form-control form-control-sm" name="return_reason"
                                        id="return_reason">
                                </div>
                            </div>

                            <!-- Submit button for Returns Section -->
                            <div class="mt-3 text-end">
                                <button type="submit" class="btn btn-success btn-sm" id="returnSubmitButton">Add
                                    Return</button>
                            </div>
                        </div>
                    </div>


                    <div class="col-12 mt-3" id="mainSubmitSection">
                        <button type="submit" class="btn btn-light text-dark" id="submitButton">Add Loan</button>
                        <button type="button" class="btn btn-secondary" id="cancelEditButton"
                            style="display:none;">Cancel</button>
                    </div>
                </div>
            </form>

            <h4>Loan Records</h4>
            <div class="table-responsive">
                <table class="table table-bordered table-sm mt-2 bg-white text-dark">
                    <thead>
                        <tr>
                            <th>විස්තරය</th>
                            <th>මුදල</th>
                            <th>විලා</th>
                            <th>Loan Type</th>
                            <th>Bill No</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loans->where('loan_type', '!=', 'returns') as $loan)
                            <tr class="loan-row" data-loan='@json($loan)'>
                                <td>{{ $loan->description }}</td>
                                <td>{{ number_format(abs($loan->amount), 2) }}</td>
                                <td>{{ $loan->customer_short_name }}</td>
                                <td>{{ ucfirst($loan->loan_type) }}</td>
                                <td>{{ $loan->bill_no ?? '-' }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-warning edit-loan-btn">Edit</button>
                                    <form action="{{ route('customers-loans.destroy', $loan->id) }}" method="POST"
                                        class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" onclick="return confirm('Are you sure?')"
                                            class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center">No loan records found.</td>
                            </tr>
                        @endforelse
                    </tbody>

                </table>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <!-- Financial Report Button -->
                    <a href="{{ route('financial.report') }}" target="_blank" class="btn btn-dark">
                        ආදායම් / වියදම්
                    </a>

                    <!-- Loan Report Button -->
                    <a href="#" data-bs-toggle="modal" data-bs-target="#reportLoanModal" class="btn btn-dark">
                        ණය වාර්තාව
                    </a>

                    <!-- Returns Report Button -->
                    <a href="{{ route('returns.report') }}" class="btn btn-dark">
                        නැවත ලබා දීම් වාර්තාව
                    </a>

                    <!-- Cheque Payments Report Button -->
                    <a href="{{ route('reports.cheque-payments') }}" class="btn btn-dark">
                        චෙක් ගෙවීම් වාර්තාව බලන්න
                    </a>

                    <!-- Set Balance Button -->
                    <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#balanceModal">
                        Set Balance
                    </button>
                </div>
                <!-- Modal -->
                <div class="modal fade" id="balanceModal" tabindex="-1" aria-labelledby="balanceModalLabel"
                    aria-hidden="true">
                    <div class="modal-dialog">
                        <form action="{{ route('settings.updateBalance') }}" method="POST">
                            @csrf
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="balanceModalLabel">Enter Balance for Today</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                                        aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="number" name="balance" class="form-control"
                                        placeholder="Enter today's balance" step="0.01" required>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-success">Save Balance</button>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>


    {{-- Include Select2 CSS --}}
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    {{-- Include jQuery and Select2 JS --}}
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const returnBtn = document.getElementById("returnSubmitButton");
            const loanForm = document.getElementById("loanForm");

            if (returnBtn) {
                returnBtn.addEventListener("click", function (e) {
                    e.preventDefault(); // stop normal form submission

                    // force loan_type = returns
                    let returnRadio = document.querySelector('input[name="loan_type"][value="returns"]');
                    if (returnRadio) {
                        returnRadio.checked = true;
                    }

                    // hide amount/description validation for returns
                    document.querySelector('[name="amount"]').removeAttribute("required");
                    document.querySelector('[name="description"]').removeAttribute("required");

                    // submit form
                    loanForm.submit();
                });
            }
        });
    </script>


    <script>
        $(document).ready(function () {
            // Initialize Select2 for relevant dropdowns
            $('#customer_id, #filter_customer, #wasted_code').select2({
                placeholder: "-- Select --",
                allowClear: true,
                width: '100%'
            });

            // Focus Select2 search field on open
            $('#customer_id, #wasted_code').on('select2:open', function () {
                setTimeout(function () {
                    $('.select2-container--open .select2-search__field').focus();
                }, 50);
            });

            // Reset form function
            function resetForm() {
                $('#loanForm')[0].reset();
                $('#loanForm').attr('action', "{{ route('customers-loans.store') }}");
                $('#methodField').val('POST');
                $('#loan_id').val('');
                $('input[name="loan_type"][value="old"]').prop('checked', true);
                $('input[name="settling_way"][value="cash"]').prop('checked', true);
                $('#customer_id').val(null).trigger('change');
                $('#submitButton').text('Add Loan').removeClass('btn-success').addClass('btn-light text-dark');
                $('#cancelEditButton').hide();
                toggleLoanTypeDependentFields();
                updateDescription();
                $('#creditLimitMessage').text('');
            }

            // Update description dynamically
            function updateDescription() {
                const loanType = $('input[name="loan_type"]:checked').val();
                const settlingWay = $('input[name="settling_way"]:checked').val();
                const descriptionField = $('#description');
                const bankField = $('#bank');
                const customerId = $('#customer_id').val();
                const totalAmountDisplay = $('#totalAmountDisplay');
                const wastedCode = $('#wasted_code').val();
                const wastedPacks = $('input[name="wasted_packs"]').val();
                const wastedWeight = $('input[name="wasted_weight"]').val();

                totalAmountDisplay.text('');
                descriptionField.val("");

                if (loanType === 'old') {
                    descriptionField.val("වෙළෙන්දාගේ ලාද පරණ නය");
                    if (settlingWay === 'cheque') {
                        const bankName = bankField.val().trim();
                        descriptionField.val(`Cheque payment from ${bankName || 'bank'}`);
                    }
                } else if (loanType === 'today') {
                    descriptionField.val("වෙළෙන්දාගේ අද දින නය ගැනීම");
                } else if (loanType === 'ingoing') {
                    descriptionField.val("වෙනත් ලාභීම/ආදායම්");
                } else if (loanType === 'outgoing') {
                    descriptionField.val("වි‍යදම්");
                } else if (loanType === 'grn_damage') {
                    if (wastedCode) {
                        descriptionField.val(`Wasted stock from code: ${wastedCode} (${wastedPacks} packs, ${wastedWeight} kg)`);
                    } else {
                        descriptionField.val("GRN Damages");
                    }
                }

                if (customerId && (loanType === 'today' || loanType === 'old')) {
                    $.ajax({
                        url: `https://wday.lk/customers/${customerId}/loans-total`,
                        method: 'GET',
                        success: function (response) {
                            // Parse the total amount
                            let totalAmount = parseFloat(response.total_amount);

                            // Remove minus sign if negative
                            if (totalAmount < 0) {
                                totalAmount = Math.abs(totalAmount);
                            }

                            // Format the amount
                            const formattedAmount = totalAmount.toLocaleString(undefined, {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });

                            // Update the display
                            totalAmountDisplay.text(`(Total Loans: ${formattedAmount})`);
                        },
                        error: function () {
                            totalAmountDisplay.text('(Could not fetch total loans)');
                        }
                    });
                }
            }

            // Toggle fields based on loan type / settling way
            function toggleLoanTypeDependentFields() {
                const loanType = $('input[name="loan_type"]:checked').val();
                const settlingWay = $('input[name="settling_way"]:checked').val();

                // Hide all dependent sections by default
                $('#settlingWaySection').addClass('d-none').find('input').prop('disabled', true);
                $('#customer_section').addClass('d-none').find('select').prop('disabled', true);
                $('#bill_no_section').addClass('d-none').find('input').prop('disabled', true);
                $('#chequeFields').addClass('d-none').find('input').prop('disabled', true);
                $('#wastedFields').addClass('d-none').find('input, select').prop('disabled', true);

                $('#loan-details-row').removeClass('d-none');
                $('#mainSubmitSection').show();
                $('input[name="amount"], input[name="description"]').prop('disabled', false).attr('required', true);

                if (loanType === 'old') {
                    $('#settlingWaySection, #customer_section, #bill_no_section').removeClass('d-none').find('input, select').prop('disabled', false);
                    if (settlingWay === 'cheque') {
                        $('#chequeFields').removeClass('d-none').find('input').prop('disabled', false);
                        $('input[name="bill_no"]').prop('disabled', true);
                    }
                } else if (loanType === 'today') {
                    $('#customer_section, #bill_no_section').removeClass('d-none').find('select, input').prop('disabled', false);
                } else if (loanType === 'ingoing' || loanType === 'outgoing') {
                    $('#customer_id').val(null).trigger('change');
                    $('input[name="bill_no"]').val('');
                } else if (loanType === 'grn_damage') {
                    $('#loan-details-row').addClass('d-none');
                    $('input[name="amount"], input[name="description"]').prop('disabled', true).removeAttr('required');
                    $('#customer_id').val(null).trigger('change');
                    $('#wastedFields').removeClass('d-none').find('input, select').prop('disabled', false);
                }
                updateDescription();
            }

            // Check credit limit
            function checkCreditLimit() {
                const loanType = $('input[name="loan_type"]:checked').val();
                const customerId = $('#customer_id').val();
                const amount = parseFloat($('input[name="amount"]').val());
                const creditLimitMessage = $('#creditLimitMessage');
                const submitButton = $('#submitButton');
                const selectedCustomerOption = $('#customer_id option:selected');
                const creditLimit = parseFloat(selectedCustomerOption.data('credit-limit'));

                creditLimitMessage.text('');
                submitButton.prop('disabled', false);

                if ((loanType === 'today' || loanType === 'old') && customerId && amount > 0) {
                    if (!isNaN(creditLimit) && amount > creditLimit) {
                        creditLimitMessage.text('Amount exceeds credit limit!');
                        submitButton.prop('disabled', true);
                    }
                }
            }
            //searc function
            // Add this to your existing JavaScript
            $(document).ready(function () {
                // Initialize Select2 with custom search
                $('#customer_id').select2({
                    placeholder: "-- Select Customer --",
                    allowClear: true,
                    width: '100%',
                    language: {
                        noResults: function () {
                            return "No customers found";
                        }
                    },
                    matcher: function (params, data) {
                        // If there's no search term, return all results
                        if ($.trim(params.term) === '') {
                            return data;
                        }

                        const searchTerm = params.term.toLowerCase();
                        const optionText = data.text.toLowerCase();

                        // If search term is only one character, use first letter matching
                        if (searchTerm.length === 1) {
                            // Check if the first letter of short name matches the search term
                            if (data.element && $(data.element).data('short-name')) {
                                const shortName = $(data.element).data('short-name').toLowerCase();
                                // Check if first letter matches
                                if (shortName.charAt(0) === searchTerm.charAt(0)) {
                                    return data;
                                }
                            }
                        }
                        // If search term has more than one character, use normal text filtering
                        else {
                            // Check if the option text contains the search term
                            if (optionText.includes(searchTerm)) {
                                return data;
                            }
                        }

                        // If no match, don't return the result
                        return null;
                    }
                });
                // Function to focus on customer search
                function focusCustomerSearch() {
                    setTimeout(function () {
                        // Open the Select2 dropdown and focus on search field
                        $('#customer_id').select2('open');
                    }, 100);
                }

                // Focus on page load
                $(document).ready(function () {
                    // Focus on customer search when page loads (only for 'old' and 'today' loan types)
                    const currentLoanType = $('input[name="loan_type"]:checked').val();
                    if (currentLoanType === 'old' || currentLoanType === 'today') {
                        focusCustomerSearch();
                    }
                });

                // Focus when radio buttons change
                $('input[name="loan_type"]').on('change', function () {
                    if (this.value === 'old' || this.value === 'today') {
                        focusCustomerSearch();
                    }
                });

                // Also add this to your existing toggleLoanTypeDependentFields function
                function toggleLoanTypeDependentFields() {
                    const loanType = $('input[name="loan_type"]:checked').val();
                    const settlingWay = $('input[name="settling_way"]:checked').val();

                    // Auto-focus for old and today loan types
                    if (loanType === 'old' || loanType === 'today') {
                        setTimeout(function () {
                            if ($('#customer_id').is(':visible')) {
                                focusCustomerSearch();
                            }
                        }, 300);
                    }
                }

                // Focus the search input when dropdown opens
                $('#customer_id').on('select2:open', function () {
                    setTimeout(function () {
                        document.querySelector('.select2-container--open .select2-search__field').focus();
                    }, 50);
                });
            });

            // Event listeners
            $('input[name="loan_type"], input[name="settling_way"]').on('change', function () {
                toggleLoanTypeDependentFields();
                checkCreditLimit();
            });
            $('#bank, #customer_id, #wasted_code, input[name="wasted_packs"], input[name="wasted_weight"]').on('change input', updateDescription);
            $('input[name="amount"]').on('input', checkCreditLimit);
            $('#customer_id').on('change', checkCreditLimit);

            // Form submission with AJAX
            $('#loanForm').on('submit', function (e) {
                e.preventDefault();
                const form = this;
                const method = $('#methodField').val();
                let url = form.action;

                // Handle PUT action dynamically
                if (method === 'PUT') {
                    const loanId = $('#loan_id').val();
                    url = `https://wday.lk/customers-loans/${loanId}`
                        ; // The URL must include the ID for update
                }

                const formData = $(form).serialize();

                $.ajax({
                    url: url,
                    type: 'POST', // Use POST for form submission and spoof the method
                    data: formData + '&_method=' + method, // Append _method to the data
                    success: function (response) {

                        location.reload();
                    },
                    error: function (xhr) {
                        let errorMsg = 'An error occurred. Check console.';
                        try {
                            const err = JSON.parse(xhr.responseText);
                            if (err.message) errorMsg = err.message;
                            else if (err.errors) errorMsg = Object.values(err.errors).flat().join('\n');
                        } catch { }
                        alert(errorMsg);
                        console.error(xhr.responseText);
                    }
                });
            });

            // Edit button
            $('.edit-loan-btn').on('click', function () {
                const loan = $(this).closest('tr').data('loan');

                // Reset form first
                resetForm();

                // Set hidden fields
                $('#loan_id').val(loan.id);
                $('#methodField').val('PUT');
                $('#loanForm').attr('action', `/customers-loans/${loan.id}`);

                // Set loan type and settling way
                $('input[name="loan_type"][value="' + loan.loan_type + '"]').prop('checked', true);
                $('input[name="settling_way"][value="' + (loan.settling_way ?? 'cash') + '"]').prop('checked', true);

                // Set common fields
                // Set amount field - remove minus sign if present (for display only)
                let displayAmount = loan.amount;
                if (displayAmount < 0) {
                    displayAmount = Math.abs(displayAmount);
                }
                $('input[name="amount"]').val(displayAmount);
                $('input[name="description"]').val(loan.description);
                $('input[name="bill_no"]').val(loan.bill_no ?? '');
                if (loan.customer_id) $('#customer_id').val(loan.customer_id).trigger('change');

                // Cheque fields
                if (loan.settling_way === 'cheque') {
                    $('#chequeFields').removeClass('d-none').find('input').prop('disabled', false);
                    $('input[name="cheque_no"]').val(loan.cheque_no ?? '');
                    $('input[name="bank"]').val(loan.bank ?? '');
                    $('input[name="cheque_date"]').val(loan.cheque_date ?? '{{ date("Y-m-d") }}');
                } else {
                    $('#chequeFields').addClass('d-none').find('input').prop('disabled', true);
                }

                // Wasted/GRN damage fields
                if (loan.loan_type === 'grn_damage') {
                    $('#loan-details-row').addClass('d-none');
                    $('input[name="amount"], input[name="description"]').prop('disabled', true).removeAttr('required');

                    $('#wastedFields').removeClass('d-none').find('input, select').prop('disabled', false);
                    $('#wasted_code').val(loan.wasted_code).trigger('change');
                    $('input[name="wasted_packs"]').val(loan.wasted_packs);
                    $('input[name="wasted_weight"]').val(loan.wasted_weight);
                }

                // Toggle other fields based on loan type
                toggleLoanTypeDependentFields();
                updateDescription();

                // Update submit button
                $('#submitButton').text('Update Loan').removeClass('btn-light text-dark').addClass('btn-success');
                $('#cancelEditButton').show();
            });

            // Cancel edit
            $('#cancelEditButton').on('click', resetForm);

            // Keyboard navigation
            // Keyboard navigation
            $('#customer_id').on('select2:close', () => $('input[name="bill_no"]').focus());
            $('input[name="bill_no"]').on('keypress', e => {
                if (e.which === 13) {
                    e.preventDefault();
                    $('input[name="amount"]').focus();
                }
            });
            $('input[name="amount"]').on('keypress', e => {
                if (e.which === 13) {
                    e.preventDefault();
                    $('input[name="description"]').focus();
                }
            });
            $('input[name="description"]').on('keypress', e => {
                if (e.which === 13) {
                    e.preventDefault();
                    // Submit the form only for these three loan types
                    const loanType = $('input[name="loan_type"]:checked').val();
                    if (['today', 'ingoing', 'outgoing'].includes(loanType)) {
                        $('#submitButton').click();
                    } else {
                        // For other loan types, you can add specific behavior or leave as is
                        $('#submitButton').click(); // or remove this line if you don't want auto-submit for other types
                    }
                }
            });
            // Form submission with Enter key
            $(document).on('keypress', function (e) {
                if (e.which === 13) {
                    // Check if we're in a text input or textarea
                    if ($(e.target).is('input:not([type="button"]):not([type="submit"]):not([type="reset"]), textarea, select')) {
                        e.preventDefault();
                        // Only submit if we're specifically in the description field for those loan types
                        if ($(e.target).is('#description') && ['today', 'ingoing', 'outgoing'].includes($('input[name="loan_type"]:checked').val())) {
                            $('#submitButton').click();
                        }
                    }
                }
            });

            // Initial setup
            resetForm();
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const returnsFields = document.getElementById('returnsFields');
            const wastedFields = document.getElementById('wastedFields');
            const chequeFields = document.getElementById('chequeFields');
            const loanDetails = document.getElementById('loan-details-row');
            const radios = document.querySelectorAll('input[name="loan_type"]');
            const loanSection = document.getElementById('submitButton');
            const amountSection = document.getElementById('amount_section');
            const descriptionSection = document.getElementById('description_section');

            radios.forEach(radio => {
                radio.addEventListener('change', function () {
                    // Hide all optional sections first
                    returnsFields.classList.add('d-none');
                    wastedFields.classList.add('d-none');
                    chequeFields.classList.add('d-none');
                    loanDetails.classList.remove('d-none');
                    amountSection.classList.remove('d-none');
                    descriptionSection.classList.remove('d-none');

                    if (this.value === 'returns') {
                        // Show returns, hide amount + description
                        returnsFields.classList.remove('d-none');
                        loanDetails.classList.add('d-none');
                        amountSection.classList.add('d-none');
                        descriptionSection.classList.add('d-none');
                        loanSection.classList.add('d-none');
                    } else if (this.value === 'grn_damage') {
                        // Show wasted, hide amount + description
                        wastedFields.classList.remove('d-none');
                        loanDetails.classList.add('d-none');
                        amountSection.classList.add('d-none');
                        descriptionSection.classList.add('d-none');
                    }
                });
            });

            // Autofill Item Code from GRN when Returns selected
            const returnGrn = document.getElementById('return_grn_code');
            if (returnGrn) {
                returnGrn.addEventListener('change', function () {
                    let code = this.value;
                    if (!code) return;
                    fetch(`https://wday.lk/api/grn-entry/${code}`)
                        .then(res => res.json())
                        .then(data => {
                            document.getElementById('return_item_code').value = data?.item_code || '';
                        });
                });
            }

            fetch('https://wday.lk/api/all-bill-nos')
                .then(res => res.json())
                .then(billNosObj => {
                    const returnBill = document.getElementById('return_bill_no');
                    returnBill.innerHTML = '<option value="">-- Select Bill --</option>';

                    // Convert object values to array
                    const billNos = Object.values(billNosObj);

                    billNos.forEach(bill => {
                        const opt = document.createElement('option');
                        opt.value = bill;
                        opt.textContent = bill;
                        returnBill.appendChild(opt);
                    });
                })
                .catch(err => console.error(err));

        });
    </script>
    <script>
        document.addEventListener('input', function (e) {
            // Detect typing inside the search box of a searchable dropdown
            const searchField = e.target.closest('.select2-search__field, .bs-searchbox input');

            if (searchField) {
                e.target.value = e.target.value.toUpperCase();
            }
        });
    </script>

    <script>
        $(document).ready(function () {
            $('#return_bill_no').select2({
                placeholder: "-- Select Bill --",
                allowClear: true
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const loanTypeRadios = document.querySelectorAll('input[name="loan_type"]');
            const descriptionSection = document.getElementById('description_section');

            // Original HTML content of the description field
            const originalDescriptionHTML = descriptionSection.innerHTML;

            // Dropdown HTML for outgoing
            const outgoingDropdownHTML = `
                                <label for="description" class="text-form-label">විස්තරය</label>
                                <select class="form-select form-select-sm" name="description" id="description" required>
                                    <option value="">-- Select --</option>
                                    <option value="Salary">Salary</option>
                                    <option value="Fuel">Fuel</option>
                                    <option value="Electricity">Electricity</option>
                                    <option value="Food">Food</option>
                                    <option value="WaterBill">WaterBill</option>
                                    <option value="Other">Other</option>
                                </select>
                                <span id="totalAmountDisplay" class="text-white-50" style="font-weight: bold; font-size: 0.9rem;"></span>
                            `;

            loanTypeRadios.forEach(radio => {
                radio.addEventListener('change', function () {
                    if (this.value === 'outgoing') {
                        descriptionSection.innerHTML = outgoingDropdownHTML;
                    } else {
                        descriptionSection.innerHTML = originalDescriptionHTML;
                    }
                });
            });
        });
    </script>




@endsection