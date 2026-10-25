const test = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const { runScreenshot } = require('./server');

function harness(nativeMessage) {
  const page = new EventEmitter();
  page.url = () => 'https://fixture.test/proof';
  page.frames = () => [];
  page.addInitScript = async () => {};
  page.getByRole = () => ({ first: () => ({ isVisible: async () => false }) });
  page.textContent = async () => null;
  page.goto = async () => {
    if (nativeMessage !== null) page.emit('dialog', {
      type: () => 'alert', message: () => nativeMessage, dismiss: async () => {},
    });
    return { status: () => 200 };
  };
  return {
    launchBrowser: async () => ({ newContext: async () => ({ newPage: async () => page, close: async () => {} }), close: async () => {} }),
    captureDesktopScreenshot: async () => 'fixture-image', wait: async () => {},
    now: () => new Date('2026-10-22T12:00:00.000Z'), dialogRenderDelayMs: 0, postCaptureDialogGraceMs: 0,
  };
}

test('screenshot returns native provenance only for a Playwright dialog event', async () => {
  const result = await runScreenshot({ url: 'https://fixture.test/proof' }, harness('native OPENBUG'));
  assert.equal(result.dialogOrigin, 'native');
  assert.deepEqual(result.dialogEvents, [{ type: 'alert', message: 'native OPENBUG', origin: 'native' }]);
  assert.deepEqual(result.hookedDialogEvents, []);
  assert.equal(result.dialogText, 'native OPENBUG');
});

test('normal screenshot never fabricates native events', async () => {
  const result = await runScreenshot({ url: 'https://fixture.test/proof' }, harness(null));
  assert.equal(result.dialogOrigin, null);
  assert.deepEqual(result.dialogEvents, []);
  assert.deepEqual(result.hookedDialogEvents, []);
});
