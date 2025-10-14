@extends('layouts.app')

@section('content')
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

<style>
    body { background-color: #99ff99; }
    .form-card { 
        background-color: #006400 !important; 
        border-radius: 12px; 
        padding: 30px 24px; 
        max-width: 900px; 
        margin: 40px auto; 
        box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
    }
    .form-label { font-weight: 700; color: #000000; }
    .form-control, .form-select { 
        border-radius: 8px; 
        padding: 10px; 
        border: 1px solid #ced4da; 
    }
    .form-control:focus, .form-select:focus { 
        border-color: #4f46e5; 
        box-shadow: 0 0 0 0.2rem rgba(79,70,229,0.25); 
        outline: none; 
    }
    .btn-success { 
        background-color: #198754; 
        border-color: #198754; 
        font-weight: 600; 
    }
    .btn-success:hover { 
        background-color: #157347; 
        border-color: #157347; 
    }
    .btn-secondary { 
        background-color: #6c757d; 
        border-color: #6c757d; 
        font-weight: 600; 
    }
    .btn-secondary:hover { 
        background-color: #5a6268; 
        border-color: #545b62; 
    }
    .text-end { text-align: right; }
    .password-protected { display: none; }
</style>

<div class="form-card p-4 shadow-sm rounded bg-light">
    <h2 class="mb-4 text-center text-primary">✏️ GRN-4 යාවත්කාලීන කිරීම</h2>

    <form method="POST" action="{{ route('grn.update', $entry->id) }}">
        @csrf
        @method('PUT')

        <div class="row g-3">

            {{-- Item Selection --}}
            <div class="col-md-4">
                <label for="item_code" class="form-label">භාණ්ඩය (Item)</label>
                <select name="item_code" id="item_code" class="form-select form-select-sm" required>
                    @foreach($items as $item)
                        <option value="{{ $item->no }}" {{ $entry->item_code == $item->no ? 'selected' : '' }}>
                            {{ $item->no }} - {{ $item->type }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label for="item_name" class="form-label">භාණ්ඩ නාමය</label>
                <input type="text" name="item_name" id="item_name" 
                       class="form-control form-control-sm" 
                       value="{{ $entry->item_name }}" required>
            </div>

            {{-- Supplier --}}
            <div class="col-md-4">
                <label for="supplier_name" class="form-label">සැපයුම්කරු <span class="text-danger">*</span></label>
                <input list="suppliers_list" id="supplier_name" name="supplier_code"
                       class="form-control form-control-sm @error('supplier_code') is-invalid @enderror" required
                       value="{{ old('supplier_code', $entry->supplier_code) }}"
                       style="text-transform: uppercase;"
                       oninput="this.value = this.value.toUpperCase();">

                <datalist id="suppliers_list">
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->code }}">
                            {{ $supplier->name ?? $supplier->code }}
                        </option>
                    @endforeach
                </datalist>

                @error('supplier_code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- GRN & Warehouse --}}
            <div class="col-md-3">
                <label for="grn_no" class="form-label">GRN අංකය</label>
                <input type="text" name="grn_no" id="grn_no" 
                       class="form-control form-control-sm" 
                       value="{{ $entry->grn_no }}" required>
            </div>

            <div class="col-md-3">
                <label for="warehouse_no" class="form-label">ගබඩා අංකය</label>
                <input type="text" name="warehouse_no" id="warehouse_no" 
                       class="form-control form-control-sm" 
                       value="{{ $entry->warehouse_no }}" required>
            </div>

            {{-- Packs & Weight --}}
            <div class="col-md-3">
                <label for="packs" class="form-label">පැක්</label>
                <input type="number" name="packs" id="packs" 
                       class="form-control form-control-sm" 
                       value="{{ $entry->packs }}" required>
            </div>

            <div class="col-md-3">
                <label for="weight" class="form-label">බර (kg) <span class="text-danger">*</span></label>
                <input type="number" id="weight" name="weight" 
                       class="form-control form-control-sm" 
                       value="{{ old('weight', $entry->weight) }}" step="0.01" min="0.01" required>
            </div>

            {{-- Transaction Date --}}
            <div class="col-md-4">
                <label for="txn_date" class="form-label">ගනුදෙනු දිනය</label>
                <input type="date" name="txn_date" id="txn_date" 
                       class="form-control form-control-sm" 
                       value="{{ $entry->txn_date }}" required>
            </div>

            {{-- Total GRN --}}
            <div class="col-md-4">
                <label for="total_grn" class="form-label">GRN මුළු එකතුව</label>
                <input type="number" id="total_grn" name="total_grn" 
                       class="form-control form-control-sm" 
                       value="{{ old('total_grn', $entry->total_grn) }}" step="0.01">
            </div>

            {{-- Per KG --}}
            <div class="col-md-4">
                <label for="per_kg_price" class="form-label">Per KG Price</label>
                <input type="number" id="per_kg_price" name="per_kg_price" 
                       class="form-control form-control-sm" 
                       value="{{ old('per_kg_price', $entry->PerKGPrice) }}" step="0.01">
            </div>

        </div>

        {{-- Buttons --}}
        <div class="text-end mt-4">
            <button type="submit" class="btn btn-success btn-sm px-4">
                <i class="material-icons align-middle me-1">update</i> යාවත්කාලීන කරන්න
            </button>
            <a href="{{ route('grn.create') }}" class="btn btn-secondary btn-sm ms-2">
                <i class="material-icons align-middle me-1">cancel</i> අවලංගු කරන්න
            </a>
        </div>
    </form>
</div>


@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordField = document.getElementById('edit_password');
    const totalGrnField = document.getElementById('total_grn_field');
    const perKgField = document.getElementById('per_kg_price_field');
    const correctPassword = 'nethma123';

    passwordField.addEventListener('input', function () {
        if(passwordField.value === correctPassword){
            totalGrnField.style.display = 'block';
            perKgField.style.display = 'block';
            passwordField.style.backgroundColor = '#d4edda';
            passwordField.style.borderColor = '#28a745';
        } else {
            totalGrnField.style.display = 'none';
            perKgField.style.display = 'none';
            passwordField.style.backgroundColor = '';
            passwordField.style.borderColor = '';
        }
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const totalGrnInput = document.getElementById('total_grn');
    const weightInput = document.getElementById('weight');
    const perKgInput = document.getElementById('per_kg_price');

    function calculatePerKg() {
        const totalGrn = parseFloat(totalGrnInput.value) || 0;
        const weight = parseFloat(weightInput.value) || 0;

        if (totalGrn > 0 && weight > 0) {
            const perKg = (totalGrn / weight).toFixed(2);
            perKgInput.value = perKg; // auto-type in field
        } else {
            perKgInput.value = '';
        }
    }

    totalGrnInput.addEventListener('input', calculatePerKg);
    weightInput.addEventListener('input', calculatePerKg);
});
</script>

@endpush
