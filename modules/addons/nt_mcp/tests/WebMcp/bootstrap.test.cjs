const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../../assets/webmcp.js'), 'utf8');

function environment(document = {}, secure = true) {
    const unexpected = () => assert.fail('The empty base must not call tools or access data');
    const window = { isSecureContext: secure };
    window.top = window;
    return vm.createContext({ window, document, fetch: unexpected, localStorage: unexpected });
}

test('unsupported browsers finish without errors or tool registration', () => {
    const context = environment();
    vm.runInContext(source, context);
    assert.equal(context.window.NTWebMCP.status, 'unsupported');
    assert.equal(context.window.NTWebMCP.toolCount, 0);
});

test('supported browsers only report readiness, without invoking the WebMCP API', () => {
    const forbidden = () => assert.fail('No tool registrations, discovery or execution allowed');
    const context = environment({ modelContext: {
        registerTool: forbidden,
        getTools: forbidden,
        executeTool: forbidden,
    } });
    vm.runInContext(source, context);
    assert.equal(context.window.NTWebMCP.status, 'ready');
    assert.equal(context.window.NTWebMCP.toolCount, 0);
});

test('missing method and policy errors are treated as unsupported', () => {
    for (const document of [
        { modelContext: {} },
        { get modelContext() { throw new Error('Policy denied'); } },
    ]) {
        const context = environment(document);
        vm.runInContext(source, context);
        assert.equal(context.window.NTWebMCP.status, 'unsupported');
    }
});

test('repeated inclusion preserves the same diagnostic state', () => {
    const context = environment();
    vm.runInContext(source, context);
    const original = context.window.NTWebMCP;
    vm.runInContext(source, context);
    assert.equal(context.window.NTWebMCP, original);
    assert.equal(Object.isFrozen(original), true);
});

test('insecure documents and frames do not become ready', () => {
    const context = environment({ modelContext: { registerTool() {} } }, false);
    vm.runInContext(source, context);
    assert.equal(context.window.NTWebMCP.status, 'unsupported');

    const frame = environment();
    frame.window.top = {};
    vm.runInContext(source, frame);
    assert.equal(Object.hasOwn(frame.window, 'NTWebMCP'), false);
});
