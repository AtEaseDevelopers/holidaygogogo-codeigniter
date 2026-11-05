// Run with: node tests/helpers/ManualLeadStatusEditorTest.js
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync('application/views/guests/index.php', 'utf8');
const start = source.indexOf('\tvar LSL_LIST_URL');
const script = source.slice(start, source.indexOf('</script>', start)).replace(/<\?php[\s\S]*?\?>/g, '/status-history');
const nodes = new Map();
function $(selector) {
    if (!nodes.has(selector)) {
        let value = '', html = '', text = '';
        nodes.set(selector, {
            val(v) { if (arguments.length) { value = v; return this; } return value; },
            html(v) { if (arguments.length) { html = v; return this; } return html || text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); },
            text(v) { text = String(v == null ? '' : v); return this; },
            on() { return this; }, toggle() { return this; },
        });
    }
    return nodes.get(selector);
}
$.trim = v => String(v || '').trim();
$.ajax = () => { throw Error('Staging must not write through AJAX'); };
const context = vm.createContext({ $, console });
vm.runInContext(script, context);
assert(context.lslPrepareSave().includes('wait'), 'Save must wait for history');
context.lslLoaded = true;
$('#lsl_status').val('FOLLOW UP'); $('#lsl_status_date').val('2026-11-05');
$('#lsl_note').val('Guest said "call tomorrow"');
assert.equal(context.lslPrepareSave(), '');
let changes = JSON.parse($('#manual_lead_status_changes').val());
assert.equal(changes.add[0].note, 'Guest said "call tomorrow"');
assert($('#lsl_list').html().includes('&quot;call tomorrow&quot;'), 'Quote escaped in editable note');
assert.equal(context.lslPrepareSave(), '');
assert.equal(JSON.parse($('#manual_lead_status_changes').val()).add.length, 1, 'No duplicate on retry');
context.lslEntries = [{ id: 10, original_note: 'old', note: 'new', can_delete: true }, { id: 11, deleted: true }];
context.lslPrepareSave();
changes = JSON.parse($('#manual_lead_status_changes').val());
assert.deepEqual(changes.notes, [{ id: 10, note: 'new' }]);
assert.deepEqual(changes.delete, [11]);
$('#lsl_note').val('note without status');
assert(context.lslPrepareSave().includes('choose a status'), 'Unassigned note must not disappear');
$('#lsl_note').val(''); $('#lsl_status').val('FOLLOW UP');
assert(context.lslStageAdd().includes('note'), 'New status must have a note');
$('#lsl_status').val('');
context.lslEntries = [{ id: 10, original_note: 'old', note: '   ' }];
assert(context.lslPrepareSave().includes('note is required'), 'Existing status note cannot be cleared');
console.log('OK: staged changes, Save without Add, editable notes, escaping, retry and load guard.');
