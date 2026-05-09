@extends('admin.layout')

@section('title', 'QR Generator - Peachy Admin')

@section('content')

<p class="page-title">QR Code Generator</p>

<div class="content-card">
    <p style="font-size:0.85rem; color:#666; margin-bottom:1.5rem;">
        Generate QR codes for each table. Customers scan the QR to go directly to the menu.
    </p>

    {{-- QR Generator Form --}}
    <div style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1.5rem;">
        <div>
            <label style="font-size:0.8rem; font-weight:600; color:#333; display:block; margin-bottom:0.3rem;">Branch</label>
            <select id="branchSelect" class="form-control-custom" style="width:200px; margin-bottom:0;">
                @foreach($branches as $branch)
                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:0.8rem; font-weight:600; color:#333; display:block; margin-bottom:0.3rem;">Table Number</label>
            <input type="number" id="tableNumber" class="form-control-custom" placeholder="e.g. 1" min="1" style="width:150px; margin-bottom:0;">
        </div>
        <button onclick="generateQR()" class="btn-primary-custom" style="padding:0.55rem 1.5rem;">
            <i class="bi bi-qr-code"></i> Generate QR
        </button>
        <button onclick="printQR()" class="btn-primary-custom" style="padding:0.55rem 1.5rem; background:#4CAF50; display:none;" id="printBtn">
            <i class="bi bi-printer"></i> Print QR
        </button>
    </div>

    {{-- QR Display --}}
    <div id="qrResult" style="display:none; text-align:center; padding:2rem; background:#f9f9f9; border-radius:12px; border:2px dashed #F4845F;">
        <div style="background:white; display:inline-block; padding:1.5rem; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.1);">
            <div style="text-align:center; margin-bottom:1rem;">
                <img src="{{ asset('images/peachy-logo.png') }}" style="width:50px; height:50px; border-radius:50%;" onerror="this.style.display='none'">
                <p style="font-size:1rem; font-weight:700; color:#F4845F; margin:0.5rem 0 0;">Peachy Cakes and Deli Cafe</p>
            </div>
            <div id="qrCode" style="margin:1rem auto;"></div>
            <p id="qrTableLabel" style="font-size:0.9rem; font-weight:600; color:#333; margin-top:0.75rem;"></p>
            <p style="font-size:0.72rem; color:#aaa; margin:0.25rem 0 0;">Scan to order</p>
        </div>
    </div>

</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    function generateQR() {
    const table = document.getElementById('tableNumber').value;
    const branch = document.getElementById('branchSelect').value;

    if (!table) {
        alert('Please enter a table number!');
        return;
    }

    const baseUrl = '{{ request()->getSchemeAndHttpHost() }}';
    const url = `${baseUrl}/customer/menu?branch_id=${branch}&table=${table}`;

    // Clear previous QR
    document.getElementById('qrCode').innerHTML = '';

    // Generate new QR
    new QRCode(document.getElementById('qrCode'), {
        text: url,
        width: 200,
        height: 200,
        colorDark: '#333333',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
    });

    document.getElementById('qrTableLabel').textContent = 'Table ' + table;
    document.getElementById('qrResult').style.display = 'block';
    document.getElementById('printBtn').style.display = 'inline-flex';
}

    function printQR() {
        const printContent = document.getElementById('qrResult').innerHTML;
        const win = window.open('', '', 'width=400,height=500');
        win.document.write(`
            <html><head><title>QR Code - Table</title>
            <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
            <style>
                body { font-family: 'Poppins', sans-serif; display:flex; justify-content:center; align-items:center; min-height:100vh; margin:0; background:#fff; }
            </style>
            </head><body>${printContent}</body></html>
        `);
        win.document.close();
        win.focus();
        setTimeout(() => {
            win.print();
            win.close();
        }, 500);
    }
</script>
@endpush