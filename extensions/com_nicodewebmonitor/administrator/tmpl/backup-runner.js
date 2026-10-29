/* Package: Nicode.joomla.webmonitor. One confirmed step at a time; never retry. */
(() => {
    'use strict';
    let active = false;
    window.addEventListener('beforeunload', event => {
        if (active) { event.preventDefault(); event.returnValue = ''; }
    });
    document.querySelectorAll('.nwm-backup-runner').forEach(form => {
        const card = form.closest('.nwm-backup');
        const pause = form.querySelector('.nwm-backup-pause');
        const progress = form.querySelector('.nwm-backup-progress');
        let stopping = false;
        form.hidden = false;
        pause.addEventListener('click', () => {
            stopping = true;
            pause.disabled = true;
            progress.textContent = 'Pausing after the current step finishes…';
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (active || !form.reportValidity()) return;
            active = true;
            stopping = false;
            // Preserve disabled state; all other local actions wait for this run.
            const controls = [...document.querySelectorAll('button, input')];
            const disabled = controls.map(control => control.disabled);
            const data = new URLSearchParams(new FormData(form));
            controls.forEach(control => { control.disabled = true; });
            pause.disabled = false;
            let failed = false, complete = false, steps = 0;
            try {
                while (!stopping && steps < 100) {
                    const expected = Number(data.get('expected_sequence'));
                    progress.textContent = `Running step ${expected + 1}. Pause will finish this request.`;
                    const response = await fetch(form.action, {
                        method: 'POST', credentials: 'same-origin', redirect: 'error',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data,
                        signal: AbortSignal.timeout(45000)
                    });
                    if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error();
                    const receipt = await response.json();
                    if (receipt.ok !== true || receipt.operation !== data.get('operation') ||
                        !['progress', 'complete'].includes(receipt.status) ||
                        !Number.isSafeInteger(receipt.next_sequence) || receipt.next_sequence !== expected + 1) throw new Error();
                    steps++;
                    data.set('expected_sequence', String(receipt.next_sequence));
                    form.elements.expected_sequence.value = String(receipt.next_sequence);
                    card.dataset.sequence = String(receipt.next_sequence);
                    complete = receipt.status === 'complete';
                    card.querySelector('h4 + p').textContent = `Site #${card.dataset.site}; state: ${receipt.status}; next sequence: ${receipt.next_sequence}. Reload to see the saved journal time.`;
                    if (complete) break;
                }
            } catch (_) {
                failed = true;
            } finally {
                active = false;
                controls.forEach((control, index) => { control.disabled = disabled[index]; });
                pause.disabled = true;
                form.elements.confirmed.checked = false;
                if (failed || complete) {
                    card.querySelectorAll('form').forEach(action => {
                        if (action.elements.task.value !== 'backupStatus') {
                            action.querySelectorAll('button, input').forEach(control => { control.disabled = true; });
                        }
                    });
                }
                if (failed) card.querySelector('h4 + p').textContent = `Site #${card.dataset.site}; result not confirmed. Query the saved journal before continuing.`;
                progress.textContent = failed
                    ? 'Stopped: result not confirmed. Query backup status before continuing. No retry was made.'
                    : complete ? 'Capture complete. Restoration has not been verified.'
                    : `Paused after ${steps} step${steps === 1 ? '' : 's'}. Confirm again to continue from sequence ${data.get('expected_sequence')}.`;
            }
        });
    });
})();
