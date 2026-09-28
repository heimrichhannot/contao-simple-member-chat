// A contact search response replaces the whole chat-search frame, including the
// field the visitor is typing in. Focus alone is not enough: a fresh input puts
// the caret at position 0, so the next keystroke lands in front of the query.
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const listeners = new Map()
const document = {
    hidden: true,
    readyState: 'loading',
    activeElement: null,
    addEventListener: (name, callback) => listeners.set(name, callback),
    querySelectorAll: () => [],
    getElementById: id => document.elements?.get(id) || null,
}
const context = vm.createContext({
    document, console, AbortController, URL,
    window: { Turbo: {}, addEventListener() {} },
    requestAnimationFrame() {}, cancelAnimationFrame() {},
    setTimeout() {}, clearTimeout() {}, setInterval() {}, clearInterval() {},
    fetch: async () => ({ ok: true }),
})
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../assets/js/member_chat.js'), 'utf8'), context)
const beforeRender = listeners.get('turbo:before-frame-render')
assert.ok(beforeRender, 'the client must wrap the chat-search frame render')

function input(value, start = value.length, end = start) {
    return {
        id: 'chat-query', value, selectionStart: start, selectionEnd: end, selectionDirection: 'none',
        focused: false, range: null,
        focus() { this.focused = true; this.selectionStart = 0; this.selectionEnd = 0 },
        setSelectionRange(from, to, direction) { this.range = [from, to, direction] },
    }
}

async function render(typed, echoed) {
    const current = input(typed)
    // Turbo builds a new element from the response; the value comes from the attribute.
    const replacement = input(echoed)
    document.activeElement = current
    document.elements = new Map([['chat-query', replacement]])
    const frame = { id: 'chat-search', contains: element => element === current, querySelector: () => current }
    const detail = { newFrame: { querySelector: () => ({ value: echoed }) }, render: async () => {} }
    beforeRender({ target: frame, detail })
    await detail.render(frame, detail.newFrame)
    return replacement
}

async function verify() {
    // The visitor stopped typing, so the response echoes the query it was made for.
    const settled = await render('anna', 'anna')
    assert.equal(settled.focused, true, 'the field keeps focus')
    assert.equal(settled.value, 'anna')
    assert.deepEqual(settled.range, [4, 4, undefined], 'the caret stays behind the query')

    // A response for an older query must neither win the field nor move the caret.
    const overtaken = await render('annab', 'anna')
    assert.equal(overtaken.range, null, 'a stale response is not rendered at all')
    console.log('PASS: the search field keeps focus and caret, and a stale response leaves it alone')
}
verify().catch(error => { console.error(error); process.exitCode = 1 })
