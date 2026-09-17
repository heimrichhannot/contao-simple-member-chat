const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const callbacks = new Map()
const listeners = new Map()
let sequence = 0
let top = 377
let measured
const log = { dataset: { chatAtBottom: 'false' } }
const root = {
    querySelector: selector => selector === '#chat-messages' ? log : { checkVisibility: () => false },
    getBoundingClientRect: () => ({ top }),
    style: { setProperty: (key, value) => { measured = value } },
}
const document = {
    hidden: true,
    readyState: 'loading',
    addEventListener: (name, callback, options) => listeners.set(name, { callback, options }),
    querySelectorAll: selector => selector === '.member-chat--with-conversation' ? [root] : [],
    getElementById: () => null,
}
const context = vm.createContext({
    document, console, AbortController,
    window: { Turbo: {}, visualViewport: { height: 812, offsetTop: 0, addEventListener() {} }, addEventListener() {} },
    requestAnimationFrame: callback => { callbacks.set(++sequence, callback); return sequence },
    cancelAnimationFrame: id => callbacks.delete(id),
    clearTimeout() {},
    fetch: async () => ({ ok: true, headers: { get: () => 'text/vnd.turbo-stream.html' }, text: async () => '<turbo-stream/>' }),
})
const source = fs.readFileSync(require('node:path').join(__dirname, '../../assets/js/member_chat.js'), 'utf8').replace(/^import .*$/m, '')
vm.runInContext(source, context)
const scroll = listeners.get('scroll')
assert.equal(scroll.options.capture, true)
assert.equal(scroll.options.passive, true)
scroll.callback(); scroll.callback()
assert.equal(callbacks.size, 1, 'scroll events must share one animation frame')
function paint() { const batch = [...callbacks.values()]; callbacks.clear(); batch.forEach(callback => callback()) }
paint()
assert.equal(measured, '435px')
top = 0
scroll.callback(); paint()
assert.equal(measured, '812px')
console.log('PASS: captured passive scroll recalculates 435px to 812px with one queued frame')
// Isolate the post-render critical section: Turbo has finished all streams,
// while the document is hidden and requestAnimationFrame never executes.
vm.runInContext(`
    active = true;
    renderStreams = async () => {};
    globalThis.live = 'polite';
    globalThis.frame = {
        isConnected: true, dataset: {}, hasAttribute: () => false,
        getAttribute: () => live, setAttribute: (name, value) => { live = value },
    };
    globalThis.button = { disabled: false, dataset: { chatUrl: '/history' }, closest: () => frame };
    globalThis.state = { loading: false, failures: 0 };
    frames.set(frame, state);
    globalThis.finished = loadMore(button);
`, context)
let deadline
Promise.race([context.finished, new Promise((_, reject) => { deadline = setTimeout(() => reject(new Error('History remained locked waiting for repaint')), 1000) })]).then(() => {
    assert.equal(context.state.loading, false)
    assert.equal(context.button.disabled, false)
    assert.equal(context.live, 'polite')
    assert.equal(callbacks.size, 0)
    console.log('PASS: hidden post-render history completes, restores aria-live and releases loading without repaint')
}).catch(error => { console.error(error); process.exitCode = 1 }).finally(() => clearTimeout(deadline))
