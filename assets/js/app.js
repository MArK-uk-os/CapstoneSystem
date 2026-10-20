document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && window.jQuery.fn.chosen) {
        window.jQuery('select:not(:disabled)').chosen({
            width: '100%',
            search_contains: true,
            disable_search_threshold: 0,
            allow_single_deselect: true,
            no_results_text: 'No matching option:'
        });
    }

    document.querySelectorAll('.alert .btn-close').forEach(function (button) {
        button.addEventListener('click', function () {
            const alert = button.closest('.alert');
            alert.classList.remove('show');
            window.setTimeout(function () {
                alert.remove();
            }, 150);
        });
    });
});
// Shared client-side scripts for the Billing and Collection Management System
document.addEventListener('DOMContentLoaded', () => {
    // Auto-dismiss alerts after 4 seconds
    document.querySelectorAll('.alert-dismissible').forEach((alert) => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) closeBtn.click();
        }, 4000);
    });
});

document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('form[data-manual-send]')) return;
    if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = '1';
    form.querySelectorAll('button').forEach(function (button) {
        button.disabled = true;
    });
}, true);
