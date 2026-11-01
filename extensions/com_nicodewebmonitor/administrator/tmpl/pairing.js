/* Package: Nicode.joomla.webmonitor. GPL-2.0-or-later. No storage or network calls. */
'use strict';
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('nwm-pairing');
    if (!form) return;
    const token = document.getElementById('nwm-credential');
    const uuid = document.getElementById('nwm-site_id');
    const digest = document.getElementById('nwm-digest');
    const status = document.getElementById('nwm-pairing-status');
    const button = document.getElementById('nwm-generate');
    const hex = bytes => Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
    let generation = 0;
    async function updateDigest() {
        const current = ++generation;
        digest.value = '';
        if (!/^[a-f0-9]{64}$/.test(token.value) || !globalThis.crypto?.subtle) return;
        const value = token.value;
        try {
            const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
            if (current === generation && token.value === value) digest.value = hex(new Uint8Array(hash));
        } catch (_) { status.textContent = 'Digest unavailable. Use the documented local PHP generator.'; }
    }
    button.addEventListener('click', async () => {
        if (token.value || uuid.value) {
            status.textContent = 'Clear both identity and credential fields deliberately before generating a replacement.';
            return;
        }
        if (!globalThis.crypto?.subtle || !crypto.getRandomValues) {
            status.textContent = 'Open this central over HTTPS or use the documented local PHP generator.';
            return;
        }
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const id = hex(bytes);
        uuid.value = `${id.slice(0,8)}-${id.slice(8,12)}-${id.slice(12,16)}-${id.slice(16,20)}-${id.slice(20)}`;
        token.value = hex(crypto.getRandomValues(new Uint8Array(32)));
        await updateDigest();
        status.textContent = 'Copy the UUID and digest to the remote plugin, save and enable it, then verify below. Keep this page open until enrollment succeeds.';
    });
    token.addEventListener('input', updateDigest);
    form.addEventListener('submit', () => {
        // The credential is submitted only to this administrator form, never in a URL.
        form.querySelector('button[type="submit"]').disabled = true;
        status.textContent = 'Checking the connector once. Wait for the result before submitting again.';
    });
    window.addEventListener('pageshow', () => { form.querySelector('button[type="submit"]').disabled = false; });
});
