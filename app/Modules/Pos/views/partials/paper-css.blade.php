{{-- Shared 80 mm paper styling for receipts, slips and reports. --}}
<style>
    @page { size: 80mm auto; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e2e8f0; font-family: "Courier New", Courier, monospace; font-size: 12px; color: #000; }
    .receipt { width: 80mm; padding: 4mm; margin: 16px auto; background: #fff; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    .big { font-size: 16px; }
    .row { display: flex; justify-content: space-between; gap: 4px; }
    .row .l { flex: 1; }
    .muted { font-size: 11px; }
    .mod { padding-left: 10px; font-size: 11px; }
    hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
    .void { border: 3px double #000; padding: 4px; margin: 6px 0; text-align: center; font-size: 16px; font-weight: bold; }
    .toolbar { position: fixed; top: 0; left: 0; right: 0; display: flex; gap: 8px; justify-content: center; padding: 12px; background: #0f172a; }
    .toolbar a, .toolbar button { min-height: 48px; padding: 0 20px; border: 0; border-radius: 12px; font: 600 16px system-ui, sans-serif; display: inline-flex; align-items: center; cursor: pointer; text-decoration: none; }
    .print { background: #10b981; color: #022c22; }
    .next { background: #1e293b; color: #f1f5f9; }
    body.has-toolbar .receipt { margin-top: 84px; } body.has-toolbar .muted + .receipt { margin-top: 8px; }
    @media print { body { background: #fff; } .receipt { margin: 0; width: 80mm; } .toolbar { display: none; } }
</style>
