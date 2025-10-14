@extends('layouts.app')

@section('content')
    <style>
        body {
            background-color: #99ff99;
        }
    </style>
    <style>
        .select2-search__field {
            text-transform: uppercase !important;
        }
    </style>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <div class="container mt-4">
        <h3>GRN Entries <span id="grn_balances" style="font-size: 25px;"></span></h3>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <form id="grn_form" method="POST" action="{{ route('grn.store3') }}">
            @csrf
            <div class="row g-3 mb-3">

                <div class="col-md-3">
                    <label for="nc_code" class="form-label">Code</label>
                    <select id="nc_code" name="code" class="form-control form-control-sm">
                        <option value="" disabled selected>-- Select Code --</option>
                        @foreach($notChangingGRNs as $grn)
                            <option value="{{ $grn->code }}" data-item-code="{{ $grn->item_code }}"
                                data-item-name="{{ $grn->item_name }}" data-grn-no="{{ $grn->grn_no }}"
                                data-perkg-price="{{ $grn->PerKGPrice }}">
                                {{ $grn->code }} - {{ $grn->item_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" id="start_date" class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" id="end_date" class="form-control form-control-sm">
                </div>

                <div class="row g-2 mb-3 align-items-end mt-2">

                    <div class="col-md-3">
                        <label for="nc_item" class="form-label">Item</label>
                        <input type="text" id="nc_item" name="item_info" class="form-control form-control-sm" readonly>
                    </div>

                    <div class="col-md-2">
                        <label for="nc_packs" class="form-label">Packs</label>
                        <input type="number" id="nc_packs" name="packs" class="form-control form-control-sm" min="1">
                    </div>

                    <div class="col-md-2">
                        <label for="nc_weight" class="form-label">Weight (kg)</label>
                        <input type="number" id="nc_weight" name="weight" class="form-control form-control-sm" step="0.01">
                    </div>

                    <div class="col-md-2">
                        <label for="nc_perkg_price" class="form-label">Per KG Price</label>
                        <input type="number" id="nc_perkg_price" name="per_kg_price" class="form-control form-control-sm"
                            step="0.01">
                    </div>

                    <div class="col-md-3">
                        <label for="nc_grn_no" class="form-label">GRN No</label>
                        <input type="text" id="nc_grn_no" name="grn_no" class="form-control form-control-sm">
                    </div>

                </div>

                <div class="col-12 d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="material-icons align-middle me-1">check_circle</i> Update GRN
                    </button>

                    <a href="{{ route('grn.create') }}" class="btn btn-secondary btn-sm">
                        <i class="material-icons align-middle me-1">add_circle</i> New GRN
                    </a>

                    <button type="button" id="export_excel" class="btn btn-success btn-sm">Export Excel</button>
                    <button type="button" id="export_pdf" class="btn btn-danger btn-sm">Export PDF</button>
                </div>
            </div>
        </form>

        <table class="table table-bordered table-striped" id="grn_table" style="display:none;">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Supplier Code</th>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Packs</th>
                    <th>Weight (kg)</th>
                    <th>Per KG Price</th>
                    <th>Txn Date</th>
                    <th>GRN No</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="grn_table_body"></tbody>
        </table>
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        const codeSelect = document.getElementById('nc_code');
        const itemInput = document.getElementById('nc_item');
        const ncGrnNo = document.getElementById('nc_grn_no');
        const perKgPriceInput = document.getElementById('nc_perkg_price');
        const packsInput = document.getElementById('nc_packs');
        const weightInput = document.getElementById('nc_weight');
        const form = document.getElementById('grn_form');
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        const table = document.getElementById('grn_table');
        const tbody = document.getElementById('grn_table_body');
        let grnEntries = @json($grnEntries);

        $(document).ready(function () {
            // Initialize Select2
            $('#nc_code').select2({
                placeholder: "Search for a code or name...",
                allowClear: true,
                minimumInputLength: 1,
                matcher: function (params, data) {
                    // If nothing typed, show all
                    if ($.trim(params.term) === '') {
                        return data;
                    }

                    const term = params.term.toUpperCase(); // convert typed input to uppercase
                    const text = (data.text || '').toUpperCase(); // convert option text to uppercase
                    const codeOnly = text.split('-')[0].trim(); // extract code (before the dash)

                    // ✅ show only codes that START with typed letters
                    if (codeOnly.startsWith(term)) {
                        return data;
                    }

                    // Hide everything else
                    return null;
                }
            });

            // --- make sure typed letters are uppercase visually ---
            $(document).on('input', '.select2-search__field', function () {
                this.value = this.value.toUpperCase();
            });


            // Function to focus & select search input
            function focusSelect2Search() {
                setTimeout(() => {
                    let searchInput = document.querySelector('.select2-container--open .select2-search__field');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }, 200);
            }

            // Focus when Select2 opens
            $(document).on('select2:open', focusSelect2Search);

            // Focus on page load
            $('#nc_code').select2('open');
            focusSelect2Search();

            // Filter table when Code changes
            $('#nc_code').on('change', function () {
                filterAndShowTable();
                const selectedCode = $(this).val();
                updateGrnBalances(selectedCode);
            });

            // Reset table on page load
            filterAndShowTable();
        });

        // --- AJAX function to update balances from database ---
        function updateGrnBalances(selectedCode) {
            if (!selectedCode) {
                $('#grn_balances').text('');
                return;
            }

            $.ajax({
                url: `https://wday.lk/AA/sms/grn/balance/${selectedCode}`, // hosted URL
                type: 'GET',
                dataType: 'json',
                success: function (data) {
                    $('#grn_balances').text(`(Balanced Packs: ${data.total_packs}, Balanced Weight: ${parseFloat(data.total_weight).toFixed(2)} kg)`);
                },
                error: function (err) {
                    console.error(err);
                }
            });
        }

        // --- AJAX Submit ---
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitForm();
        });

        function submitForm() {
            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                headers: {
                    "X-CSRF-TOKEN": '{{ csrf_token() }}',
                    "Accept": "application/json"
                },
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        grnEntries.push(data.entry);
                        filterAndShowTable();

                        // Clear numeric fields only
                        packsInput.value = '';
                        weightInput.value = '';
                        perKgPriceInput.value = '';

                        // Focus back to Code dropdown
                        $('#nc_code').select2('open');
                        setTimeout(() => {
                            let searchInput = document.querySelector('.select2-container--open .select2-search__field');
                            if (searchInput) { searchInput.focus(); searchInput.select(); }
                        }, 200);

                        // Update balances
                        updateGrnBalances($('#nc_code').val());

                    } else {
                        alert('Error adding entry: ' + data.message);
                    }
                })
                .catch(err => { console.error(err); alert('Error adding entry'); });
        }

        // --- Delete entry via AJAX ---
        document.addEventListener("click", function (e) {
            if (e.target.closest(".delete-btn")) {
                const btn = e.target.closest(".delete-btn");
                const id = btn.dataset.id;
                const row = btn.closest("tr");

                if (!confirm("Are you sure you want to delete this entry?")) return;

                const url = '{{ route("grnupdate.delete", ":id") }}'.replace(':id', id);

                fetch(url, {
                    method: "DELETE",
                    headers: {
                        "X-CSRF-TOKEN": '{{ csrf_token() }}',
                        "Content-Type": "application/json",
                        "Accept": "application/json"
                    },
                    body: JSON.stringify({ id: id })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            row.remove();
                            const index = grnEntries.findIndex(e => e.id == id);
                            if (index > -1) grnEntries.splice(index, 1);
                            filterAndShowTable();

                            // Re-focus Select2 search input
                            $('#nc_code').select2('open');
                            setTimeout(() => {
                                let searchInput = document.querySelector('.select2-container--open .select2-search__field');
                                if (searchInput) { searchInput.focus(); searchInput.select(); }
                            }, 200);

                            // Update balances after deletion
                            updateGrnBalances($('#nc_code').val());

                        } else alert('Delete failed: ' + data.message);
                    })
                    .catch(err => console.error(err));
            }
        });

        // --- Filter & Show Table ---
        function filterAndShowTable() {
            const selectedCode = $('#nc_code').val();
            const selectedOption = $('#nc_code').find(':selected');

            if (!selectedCode) {
                itemInput.value = '';
                ncGrnNo.value = '';
                perKgPriceInput.value = '';
                tbody.innerHTML = '';
                table.style.display = 'none';
                $('#grn_balances').text('');
                return;
            }

            const codeEntries = grnEntries.filter(e => e.code == selectedCode);

            const filteredEntries = codeEntries.filter(entry => {
                const startCheck = startDateInput.value ? new Date(entry.txn_date) >= new Date(startDateInput.value) : true;
                const endCheck = endDateInput.value ? new Date(entry.txn_date) <= new Date(endDateInput.value) : true;
                return startCheck && endCheck;
            });

            // Sort by id in descending order
            filteredEntries.sort((a, b) => b.id - a.id);

            itemInput.value = selectedOption.data('item-name') || '';
            ncGrnNo.value = selectedOption.data('grn-no') || '';
            perKgPriceInput.value = selectedOption.data('perkg-price') || '';

            tbody.innerHTML = '';
            filteredEntries.forEach(entry => {
                const row = document.createElement('tr');
                row.innerHTML = `
                <td>${entry.id}</td>
                <td>${entry.code}</td>
                <td>${entry.supplier_code}</td>
                <td>${entry.item_code}</td>
                <td>${entry.item_name}</td>
                <td>${entry.packs}</td>
                <td>${entry.weight}</td>
                <td>${entry.per_kg_price}</td>
                <td>${entry.txn_date}</td>
                <td>${entry.grn_no}</td>
                <td>
                    <button class="btn btn-danger btn-sm delete-btn" data-id="${entry.id}">
                        <i class="material-icons align-middle">delete</i>
                    </button>
                </td>
            `;
                tbody.appendChild(row);
            });

            table.style.display = filteredEntries.length ? '' : 'none';
        }

        // --- Keyboard Navigation ---
        $('#nc_code').on('select2:select', () => { setTimeout(() => { packsInput.focus(); packsInput.select(); }, 100); });
        $(document).on('keydown', '.select2-container--open .select2-search__field', e => {
            if (e.key === "Enter") { e.preventDefault(); packsInput.focus(); packsInput.select(); }
        });
        packsInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); weightInput.focus(); } });
        weightInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); perKgPriceInput.focus(); } });
        perKgPriceInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); submitForm(); } });

        // --- Export ---
        function exportData(url) {
            const f = document.createElement('form'); f.method = 'POST'; f.action = url; f.style.display = 'none';
            const csrf = document.createElement('input'); csrf.name = '_token'; csrf.value = '{{ csrf_token() }}'; f.appendChild(csrf);
            const dataInput = document.createElement('input'); dataInput.name = 'entries';
            dataInput.value = JSON.stringify(Array.from(tbody.querySelectorAll('tr')).map(tr => {
                const tds = tr.querySelectorAll('td');
                return { id: tds[0].innerText, code: tds[1].innerText, supplier_code: tds[2].innerText, item_code: tds[3].innerText, item_name: tds[4].innerText, packs: tds[5].innerText, weight: tds[6].innerText, per_kg_price: tds[7].innerText, txn_date: tds[8].innerText, grn_no: tds[9].innerText };
            }));
            f.appendChild(dataInput); document.body.appendChild(f); f.submit();
        }

        document.getElementById('export_excel').addEventListener('click', () => exportData('{{ route("grn.export.excel") }}'));
        document.getElementById('export_pdf').addEventListener('click', () => exportData('{{ route("grn.export.pdf") }}'));
    </script>

@endsection