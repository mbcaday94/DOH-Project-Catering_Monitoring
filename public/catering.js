const themeToggle = document.querySelector('[data-theme-toggle]');
if (themeToggle) {
    const page = document.documentElement;
    themeToggle.setAttribute('aria-checked', String(page.dataset.theme === 'dark'));
    themeToggle.hidden = false;

    themeToggle.addEventListener('click', () => {
        const theme = page.dataset.theme === 'dark' ? 'light' : 'dark';
        page.dataset.theme = theme;
        themeToggle.setAttribute('aria-checked', String(theme === 'dark'));

        try {
            localStorage.setItem('catering-theme', theme);
        } catch {
            // The selected theme remains active for this page.
        }
    });
}

document.querySelectorAll('[data-open-dialog]').forEach((button) => {
    const dialog = document.getElementById(button.dataset.openDialog);

    button.addEventListener('click', () => {
        const preview = dialog.querySelector('iframe[data-preview-src]');
        if (preview && !preview.hasAttribute('src')) {
            preview.src = preview.dataset.previewSrc;
        }
        dialog.showModal();
    });
});

document.querySelectorAll('dialog[data-auto-open]').forEach((dialog) => {
    dialog.showModal();
    dialog.querySelector('[aria-invalid="true"]')?.focus();
});

document.querySelectorAll('[data-billing-form]').forEach((form) => {
    const billingOrder = form.elements.namedItem('purchase_order_id');
    const billingCaterer = form.querySelector('[data-billing-caterer]');
    const billingEndUser = form.elements.namedItem('forwarded_to_end_user');
    const partialAmount = form.elements.namedItem('partial_amount');
    const remainingBalance = form.querySelector('[data-billing-balance]');
    const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const updateBillingBalance = () => {
        const balanceCents = billingOrder.selectedOptions[0]?.dataset.balanceCents;
        remainingBalance.textContent = balanceCents
            ? currency.format((Number(balanceCents) - Math.round(Number(partialAmount.value) * 100)) / 100)
            : '—';
    };
    const updateBillingCaterer = () => {
        billingCaterer.value = billingOrder.selectedOptions[0]?.dataset.caterer ?? '';
    };

    billingOrder.addEventListener('change', () => {
        updateBillingCaterer();
        billingEndUser.value = billingOrder.selectedOptions[0]?.dataset.endUser ?? '';
        updateBillingBalance();
    });
    partialAmount.addEventListener('input', updateBillingBalance);
    updateBillingCaterer();
    updateBillingBalance();
});

const scanInput = document.querySelector('[data-scan-url]');

if (scanInput) {
    const form = scanInput.form;
    const status = document.querySelector('#po-scan-status');
    const normalizeProvince = (value) => value.toUpperCase().replace(/[^A-Z]/g, '');

    scanInput.addEventListener('change', async () => {
        const file = scanInput.files[0];
        if (!file) return;

        if (file.size > 2 * 1024 * 1024) {
            status.textContent = 'Choose a file no larger than 2 MB.';
            scanInput.value = '';
            return;
        }

        scanInput.disabled = true;
        status.textContent = 'Reading the scanned purchase order…';
        const body = new FormData();
        body.append('scan', file);

        try {
            const response = await fetch(scanInput.dataset.scanUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': form.elements.namedItem('_token').value,
                },
                body,
            });

            if (response.status === 419 || response.status === 401) {
                status.textContent = 'Your session expired. Sign in again before scanning.';
                return;
            }
            if (response.status === 429) {
                status.textContent = 'Too many scans. Try again in a minute.';
                return;
            }

            const result = await response.json();
            if (!response.ok) {
                status.textContent = result.errors?.scan?.[0] ?? 'The scan could not be read.';
                return;
            }

            let filled = 0;
            for (const [name, value] of Object.entries(result.fields)) {
                const field = form.elements.namedItem(name);
                if (!field || field.value.trim()) continue;

                if (field instanceof HTMLSelectElement) {
                    const match = Array.from(field.options).find((option) =>
                        option.value && normalizeProvince(option.text) === normalizeProvince(value)
                    );
                    if (match) field.value = match.value;
                } else {
                    field.value = value;
                }
                if (field.value) filled++;
            }

            status.textContent = filled
                ? `Filled ${filled} ${filled === 1 ? 'field' : 'fields'}. Review the values before saving.`
                : 'No empty fields could be filled. Enter the details manually or try a clearer scan.';
        } catch {
            status.textContent = 'The scan could not be read. Try again or enter the details manually.';
        } finally {
            scanInput.disabled = false;
            scanInput.value = '';
        }
    });
}
