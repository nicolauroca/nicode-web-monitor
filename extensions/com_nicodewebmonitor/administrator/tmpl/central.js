/* Progressive UI only. Native forms, permissions and protocol remain on the server. */
(() => {
    'use strict';
    const root = document.querySelector('.nwm-central');
    if (!root) return;
    const normalise = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    const search = root.querySelector('#nwm-site-search');
    const state = root.querySelector('#nwm-site-state');
    const sites = [...root.querySelectorAll('[data-nwm-site]')];
    const filter = () => {
        if (!search || !state) return;
        const query = normalise(search.value.trim());
        let count = 0;
        sites.forEach(site => {
            site.hidden = !(normalise(site.dataset.search).includes(query) && (state.value === 'all' || site.dataset.state === state.value));
            if (!site.hidden) count++;
        });
        root.querySelector('[data-nwm-filter-count]').textContent = `${count} de ${sites.length} sitios`;
        root.querySelector('[data-nwm-no-results]').hidden = count !== 0;
        root.querySelectorAll('[data-nwm-filter]').forEach(link => {
            if (link.dataset.nwmFilter === state.value) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
    };
    root.querySelectorAll('[data-nwm-filter-controls]').forEach(control => { control.hidden = false; });
    search?.addEventListener('input', filter);
    state?.addEventListener('change', filter);
    root.querySelector('[data-nwm-clear-filters]')?.addEventListener('click', () => { search.value = ''; state.value = 'all'; filter(); search.focus(); });
    root.querySelectorAll('[data-nwm-filter]').forEach(link => link.addEventListener('click', () => {
        if (state) { state.value = link.dataset.nwmFilter; filter(); }
    }));
    filter();
    root.querySelectorAll('[data-nwm-reveal]').forEach(button => {
        button.hidden = false;
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.nwmReveal);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Ocultar' : 'Mostrar';
            button.setAttribute('aria-pressed', String(show));
        });
    });
    root.querySelectorAll('[data-nwm-copy]').forEach(button => {
        button.hidden = false;
        button.addEventListener('click', async () => {
            const input = document.getElementById(button.dataset.nwmCopy);
            const status = root.querySelector('[data-nwm-copy-status]');
            try {
                if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
                await navigator.clipboard.writeText(input.value);
                status.textContent = button.dataset.nwmCopy === 'pairing-code' ? 'Código copiado.' : 'Dirección copiada.';
            } catch (_) {
                input.type = 'text'; input.focus(); input.select();
                const reveal = root.querySelector('[data-nwm-reveal="' + input.id + '"]');
                if (reveal) { reveal.textContent = 'Ocultar'; reveal.setAttribute('aria-pressed','true'); }
                status.textContent = 'Copia el texto seleccionado con el menú del navegador o Ctrl+C.';
            }
        });
    });
    const extensionSearch = root.querySelector('#nwm-extension-search');
    if (extensionSearch) {
        root.querySelector('[data-nwm-extension-filter]').hidden = false;
        const rows = [...root.querySelectorAll('[data-nwm-extension]')];
        extensionSearch.addEventListener('input', () => {
            const query = normalise(extensionSearch.value.trim());
            rows.forEach(row => { row.hidden = !normalise(row.textContent).includes(query); });
            const count = rows.filter(row => !row.hidden).length;
            root.querySelector('[data-nwm-extension-count]').textContent = `${count} de ${rows.length} extensiones`;
            root.querySelector('[data-nwm-no-extensions]').hidden = count !== 0;
        });
    }
    root.querySelectorAll('form[data-nwm-submit]').forEach(form => form.addEventListener('submit', () => {
        if (!form.checkValidity()) return;
        form.setAttribute('aria-busy', 'true');
        const label = form.querySelector('[data-nwm-button-label]');
        if (label) label.textContent = 'Procesando…';
        // Keep the native submit action and all fields intact.
        form.querySelector('button[type=submit]')?.setAttribute('disabled', '');
    }));
    window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
})();
