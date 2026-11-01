/* Package: Nicode.joomla.webmonitor. Browser event logic with real Web Crypto. */
const vm = require('node:vm');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const {webcrypto, createHash} = require('node:crypto');
const elements = new Map();
for (const id of ['nwm-pairing','nwm-credential','nwm-site_id','nwm-digest','nwm-pairing-status','nwm-generate','submit']) {
    elements.set(id, {value:'', handlers:{}, addEventListener(event, fn){this.handlers[event]=fn;}, querySelector(){return elements.get('submit');}});
}
const document={getElementById:id=>elements.get(id),addEventListener(event,fn){fn();}};
const window={addEventListener(){}};
vm.runInNewContext(fs.readFileSync(__dirname+'/../extensions/com_nicodewebmonitor/administrator/tmpl/pairing.js','utf8'),{document,window,crypto:webcrypto,TextEncoder,Uint8Array});
(async()=>{
    await elements.get('nwm-generate').handlers.click();
    const token=elements.get('nwm-credential').value;
    const uuid=elements.get('nwm-site_id').value;
    assert.match(token,/^[a-f0-9]{64}$/);
    assert.match(uuid,/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);
    assert.equal(elements.get('nwm-digest').value,createHash('sha256').update(token).digest('hex'));
    await elements.get('nwm-generate').handlers.click();
    assert.equal(elements.get('nwm-credential').value,token);
    elements.get('nwm-credential').value='';
    await elements.get('nwm-credential').handlers.input();
    assert.equal(elements.get('nwm-digest').value,'');
    elements.get('nwm-pairing').handlers.submit();
    assert.equal(elements.get('submit').disabled,true);
    console.log(JSON.stringify({passed:6,coverage:'uuid, credential entropy length, digest, no overwrite, digest invalidation, submit lock'}));
})().catch(error=>{console.error(error);process.exitCode=1;});
