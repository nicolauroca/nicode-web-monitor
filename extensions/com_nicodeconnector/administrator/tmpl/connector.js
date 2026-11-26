/* Progressive UI only. Native POST, CSRF, validation and backend authorization remain in Joomla. */
'use strict';
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.nwm-connector');
    if (!root) return;
    const address = root.querySelector('#central-url');
    const code = root.querySelector('#connection-code');
    const useLocal = root.querySelector('[data-nwm-use-local]');
    if (useLocal && address && code) {
        useLocal.hidden = false;
        useLocal.addEventListener('click', event => {
            address.value = event.currentTarget.dataset.localUrl;
            address.dispatchEvent(new Event('input', {bubbles: true}));
            code.focus();
        });
    }
    const toggle = root.querySelector('[data-nwm-toggle-code]');
    if (toggle && code) {
        toggle.hidden = false;
        toggle.addEventListener('click', () => {
            const visible = code.type === 'password';
            code.type = visible ? 'text' : 'password';
            toggle.textContent = visible ? 'Ocultar' : 'Mostrar';
            toggle.setAttribute('aria-pressed', String(visible));
            code.focus();
        });
        window.addEventListener('pageshow', () => {
            code.type = 'password';
            toggle.textContent = 'Mostrar';
            toggle.setAttribute('aria-pressed', 'false');
        });
    }
    root.querySelectorAll('.nwm-field input').forEach(input => {
        const error = root.querySelector(`#${input.id}-error`);
        input.addEventListener('invalid', () => {
            input.setAttribute('aria-invalid', 'true');
            if (error) {
                error.textContent = input.id === 'central-url'
                    ? 'Introduce la dirección completa del central, empezando por https://.'
                    : 'Pega el código completo que has generado en el central.';
                error.hidden = false;
            }
        });
        input.addEventListener('input', () => {
            input.removeAttribute('aria-invalid');
            if (error) { error.hidden = true; error.textContent = ''; }
        });
    });
    root.querySelectorAll('[data-nwm-submit]').forEach(form => {
        const button = form.querySelector('button[type="submit"]');
        const label = button?.querySelector('[data-nwm-button-label]');
        const status = form.querySelector('[data-nwm-submit-status]');
        const original = label?.textContent;
        form.addEventListener('submit', () => {
            if (!form.checkValidity()) return;
            form.setAttribute('aria-busy', 'true');
            if (button) button.disabled = true;
            const connecting = form.querySelector('input[name="task"]').value === 'connect';
            if (label) label.textContent = connecting ? 'Conectando…' : 'Desconectando…';
            if (status) {
                status.textContent = connecting ? 'Verificando la conexión. Espera la respuesta del central.' : 'Revocando el acceso de inventario…';
                status.hidden = false;
            }
        });
        window.addEventListener('pageshow', () => {
            form.removeAttribute('aria-busy');
            if (button) button.disabled = false;
            if (label) label.textContent = original;
            if (status) status.hidden = true;
        });
    });
});
