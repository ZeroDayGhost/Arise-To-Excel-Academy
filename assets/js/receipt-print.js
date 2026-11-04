(function () {
    'use strict';

    const thermalPrintStyles = `
        @page { size: 58mm 100mm; margin: 2mm; }
        * { box-sizing: border-box; }
        html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; background: #fff; color: #000; }
        body { font: 8pt/1.3 "Courier New", Courier, monospace; }
        .thermal-receipt { display: block; width: 54mm; max-width: calc(100vw - 4mm); margin: 0 auto; padding: 1mm 1mm 0; }
        .thermal-receipt * { box-sizing: border-box; min-width: 0; color: #000; text-shadow: none; box-shadow: none; overflow-wrap: break-word; }
        .thermal-receipt-header { display: grid; justify-items: center; gap: 2px; padding: 0 0 6px; text-align: center; }
        .thermal-receipt-logo { display: block; width: 30mm; max-width: 30mm; max-height: 30mm; margin: 0 auto 2px; object-fit: contain; filter: grayscale(1) contrast(1.15); }
        .thermal-receipt-header strong { font-size: 9pt; line-height: 1.2; font-weight: 800; text-transform: uppercase; }
        .thermal-receipt-header span { font-size: 9pt; font-weight: 700; }
        .thermal-receipt-header b { margin-top: 3px; font-size: 10pt; }
        .thermal-receipt-row { display: grid; grid-template-columns: minmax(0, 42%) minmax(0, 58%); align-items: start; gap: 1mm; padding: 2px 0; }
        .thermal-receipt-row > span { text-align: left; }
        .thermal-receipt-row > strong { text-align: right; font-weight: 700; }
        .thermal-receipt-number strong { font-size: 9pt; }
        .thermal-receipt-type strong { text-transform: uppercase; }
        .thermal-receipt-field { display: grid; gap: 1px; padding: 3px 0; }
        .thermal-receipt-student span { font-size: 7pt; font-weight: 700; text-transform: uppercase; }
        .thermal-receipt-student strong { font-size: 10pt; font-weight: 800; }
        .thermal-receipt-field strong { font-weight: 700; }
        .thermal-receipt-divider { border-top: 1px dashed #000; margin: 5px 0; }
        .thermal-receipt-amount { display: grid; gap: 2px; padding: 5px 0; text-align: center; }
        .thermal-receipt-amount span { font-size: 8pt; }
        .thermal-receipt-amount strong { font-size: 17pt; line-height: 1.15; font-weight: 800; }
        .thermal-receipt-balance { margin-top: 2px; font-weight: 700; }
        .thermal-receipt-footer { display: grid; gap: 2px; margin-top: 6px; padding-top: 5px; border-top: 1px dashed #000; text-align: center; font-size: 7pt; }
        .thermal-receipt-signoff { display: flex; align-items: center; justify-content: center; gap: 5px; margin-top: 3px; font: italic 13pt/1.2 Georgia, serif; }
        .thermal-receipt-signoff::before, .thermal-receipt-signoff::after { content: ""; width: 10mm; border-top: 1px solid #000; }
    `;

    window.printThermalReceipt = function () {
        const receipt = document.querySelector('.thermal-receipt');
        if (!receipt) return;

        const printWindow = window.open('', '_blank', 'width=480,height=800');
        if (!printWindow) {
            window.alert('Allow pop-ups for this site to print the thermal receipt.');
            return;
        }

        printWindow.document.open();
        printWindow.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Thermal receipt</title><style>' + thermalPrintStyles + '</style></head><body>' + receipt.outerHTML + '</body></html>');
        printWindow.document.close();
        printWindow.addEventListener('afterprint', function () {
            printWindow.close();
        }, { once: true });
        const printAfterImagesLoad = function () {
            printWindow.requestAnimationFrame(function () {
                const printReceipt = printWindow.document.querySelector('.thermal-receipt');
                const style = printWindow.document.querySelector('style');
                if (printReceipt && style) {
                    const contentHeightMm = printReceipt.getBoundingClientRect().height * 25.4 / 96;
                    const pageHeightMm = Math.max(40, Math.ceil(contentHeightMm + 4));
                    style.textContent = thermalPrintStyles.replace(
                        '@page { size: 58mm 100mm; margin: 2mm; }',
                        '@page { size: 58mm ' + pageHeightMm + 'mm; margin: 2mm; }'
                    );
                }
                printWindow.focus();
                printWindow.print();
            });
        };
        const imageLoads = Array.from(printWindow.document.images || []).map(function (image) {
            if (image.complete) return Promise.resolve();
            return new Promise(function (resolve) {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        });
        Promise.all(imageLoads).then(printAfterImagesLoad);
    };

    function clearPrintMode() {
        document.body.removeAttribute('data-receipt-print-mode');
    }

    window.addEventListener('afterprint', clearPrintMode);
}());
