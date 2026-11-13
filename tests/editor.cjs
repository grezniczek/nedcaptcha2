// Run with: npm test
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const jquery = require('jquery');

const source = fs.readFileSync(path.join(__dirname, '../js/nedCAPTCHA2.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

async function fixture(t) {
    const annotations = ['@NEDCAPTCHA', '@nedCAPTCHA', '@nedcaptcha', '@NEDCAPTCHA-INSTRUCTIONS', '@NEDCAPTCHA_EXTRA', '@NEDCAPTCHAX'];
    const rows = annotations.map((tag, i) => `<tr id="field${i}-tr"><td class="actiontags"><code>${tag}</code></td></tr>`).join('');
    const dom = new JSDOM(`<body><table>${rows}</table></body>`, { runScripts: 'outside-only' });
    t.after(() => dom.window.close());
    const { window } = dom;
    const $ = jquery(window);
    window.$ = window.jQuery = $;
    window.lang = { global_53: 'Cancel' };
    window.setSurveyQuestionNumbers = () => {};
    // An attempted call to either shared toast API must fail the test.
    window.showToast = () => { throw new Error('Shared REDCap toast must not be used'); };
    window.bootstrap = {
        Tooltip: function() {},
        Toast: function() { throw new Error('Bootstrap Toast must not be used'); }
    };
    $.fn.dialog = function(command) {
        if (typeof command === 'object') {
            this.data('dialog-options', command);
            command.open.call(this[0]);
        }
        else if (command === 'close') {
            const options = this.data('dialog-options');
            assert.ok(options, 'Cannot close a destroyed dialog');
            options.close.call(this[0]);
        }
        else if (command === 'destroy') this.removeData('dialog-options');
        return this;
    };
    // Control notice timers so dismissal/replacement races are deterministic.
    const timers = [];
    const originalTimeout = window.setTimeout.bind(window);
    const originalClear = window.clearTimeout.bind(window);
    window.setTimeout = (callback, delay, ...args) => {
        if (delay !== 3000) return originalTimeout(callback, delay, ...args);
        timers.push({ callback, cleared: false });
        return 10000 + timers.length - 1;
    };
    window.clearTimeout = id => {
        if (id >= 10000) timers[id - 10000].cleared = true;
        else originalClear(id);
    };
    const state = { requests: [], loaded: [], result: { errors: [], warnings: [] }, timers };
    const jsmo = {
        ajax(action, payload) {
            state.requests.push({ action, payload: JSON.parse(JSON.stringify(payload)) });
            if (action === 'get-params') {
                const data = {
                    params: { type: 'math' }, keys: ['type'],
                    html: '<input type="radio" name="type" value="math" checked><input type="radio" name="type" value="image"><input type="radio" name="type" value="none">'
                };
                state.loaded.push(data);
                return Promise.resolve(data);
            }
            return state.result instanceof Error ? Promise.reject(state.result) : Promise.resolve(state.result);
        }
    };
    window.eval(source);
    const em = window.DE_RUB_SEG_nedCAPTCHA2;
    em.init({ tagged: annotations.map((_, i) => `field${i}`), at: '@NEDCAPTCHA', linkTitle: 'Edit', updateLabel: 'Update' }, jsmo);
    await new Promise(resolve => $(resolve));
    await tick();
    const editor = () => $('#nedCAPTCHA-OD-editor');
    return {
        $, state, editor,
        async open() { em.edit('field0'); await tick(); },
        change(type) { editor().find(`input[value="${type}"]`).prop('checked', true).trigger('input'); },
        update() { editor().data('dialog-options').buttons[1].click(); },
        cancel() { editor().data('dialog-options').buttons[0].click(); }
    };
}

test('only the exact uppercase setup tag receives an editor link', async t => {
    const { $ } = await fixture(t);
    assert.equal($('#field0-tr a.nedCAPTCHA-OD-link').length, 1);
    assert.equal($('a.nedCAPTCHA-OD-link').length, 1);
});

test('successful saves close the dialog and use a dismissible module notice', async t => {
    const f = await fixture(t);
    await f.open();
    f.change('none');
    f.update();
    await tick();
    assert.equal(f.state.requests.at(-1).payload.params.type, 'none');
    assert.equal(f.editor().data('dialog-options'), undefined);
    assert.match(f.$('[role="status"]').text(), /configuration has been updated/);
    f.$('.nedCAPTCHA-OD-notice .btn-close').trigger('click');
    assert.equal(f.$('.nedCAPTCHA-OD-notice').length, 0);
    assert.equal(f.state.timers[0].cleared, true);
    assert.doesNotThrow(() => f.state.timers[0].callback());
});

test('errors and warnings remain in the dialog, render as text, and allow retry', async t => {
    const f = await fixture(t);
    await f.open();
    f.change('image');
    f.state.result = { errors: ['<img src=x onerror=alert(1)>'], warnings: [] };
    f.update();
    await tick();
    assert.match(f.editor().find('[role="alert"]').text(), /<img src=x/);
    assert.equal(f.editor().find('img').length, 0);
    assert.ok(f.editor().data('dialog-options'));
    f.state.result = { errors: [], warnings: ['Fix this setting'] };
    f.update();
    await tick();
    assert.equal(f.editor().find('.nedCAPTCHA-OD-notice').length, 1);
    assert.match(f.editor().find('.alert-warning').text(), /Fix this setting/);
    f.state.result = { errors: [], warnings: [] };
    f.update();
    await tick();
    assert.equal(f.editor().data('dialog-options'), undefined);
    assert.equal(f.$('.nedCAPTCHA-OD-notice-success').length, 1);
});

test('reopening does not keep old input handlers or let old timers remove a new notice', async t => {
    const f = await fixture(t);
    await f.open();
    f.change('none');
    f.update();
    await tick();
    await f.open();
    f.change('image');
    assert.equal(f.state.loaded[0].params.type, 'none', 'Closed editor params were modified');
    f.update();
    await tick();
    assert.equal(f.state.requests.filter(r => r.action === 'set-params').length, 2);
    assert.equal(f.$('.nedCAPTCHA-OD-notice-success').length, 1);
    f.state.timers[0].callback();
    assert.equal(f.$('.nedCAPTCHA-OD-notice-success').length, 1);
    f.state.timers[1].callback();
    assert.equal(f.$('.nedCAPTCHA-OD-notice-success').length, 0);
});

test('network failure keeps the editor open and a later retry can succeed', async t => {
    const f = await fixture(t);
    await f.open();
    f.change('none');
    f.state.result = new Error('Network failure');
    f.update();
    await tick();
    assert.match(f.editor().find('[role="alert"]').text(), /unknown error occurred/);
    assert.ok(f.editor().data('dialog-options'));
    f.state.result = { errors: [], warnings: [] };
    f.update();
    await tick();
    assert.equal(f.editor().data('dialog-options'), undefined);
});

test('duplicate updates and replies to a closed dialog do not disrupt a new editor', async t => {
    const f = await fixture(t);
    await f.open();
    f.change('none');
    let resolveSave;
    f.state.result = new Promise(resolve => { resolveSave = resolve; });
    f.update();
    f.update();
    assert.equal(f.state.requests.filter(r => r.action === 'set-params').length, 1);
    f.cancel();
    await f.open();
    resolveSave({ errors: [], warnings: [] });
    await tick();
    assert.ok(f.editor().data('dialog-options'));
    assert.equal(f.$('.nedCAPTCHA-OD-notice').length, 0);
});

test('edits made during a save stay open and can be saved in a second request', async t => {
    const f = await fixture(t);
    await f.open();
    f.change('none');
    let finish;
    f.state.result = new Promise(resolve => { finish = resolve; });
    f.update();
    f.change('image');
    finish({ errors: [], warnings: [] });
    await tick();
    assert.equal(f.state.requests.at(-1).payload.params.type, 'none');
    assert.ok(f.editor().data('dialog-options'));
    assert.equal(f.state.loaded.at(-1).params.type, 'image');
    assert.match(f.editor().find('.alert-info').text(), /later changes are still unsaved/);
    f.state.result = { errors: [], warnings: [] };
    f.update();
    await tick();
    assert.equal(f.state.requests.at(-1).payload.params.type, 'image');
    assert.equal(f.editor().data('dialog-options'), undefined);
});
