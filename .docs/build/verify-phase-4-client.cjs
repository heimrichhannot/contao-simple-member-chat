const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const listeners = new Map()
let reloads = 0
let scheduled = 0
const document = {
    hidden: false, readyState: 'loading',
    addEventListener: (name, callback) => listeners.set(name, callback),
    querySelectorAll: () => [],
}
const context = vm.createContext({
    document, console, AbortController,
    window: { Turbo: {}, addEventListener() {} },
    setTimeout: () => ++scheduled, clearTimeout() {},
    requestAnimationFrame() {}, cancelAnimationFrame() {},
    fetch: () => { throw new Error('A badge must never use incremental stream fetches') },
})
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../../assets/js/member_chat.js'), 'utf8'), context)
const badge = {
    id: 'chat-unread', isConnected: true, src: '', loaded: Promise.resolve(),
    dataset: { chatPoll: 'badge', chatMode: 'full', chatUrl: '/_member_chat/unread', chatPollInterval: '30000', chatPollMaxInterval: '240000' },
    hasAttribute: name => name === 'complete',
    checkVisibility: () => false, // An empty inline frame has no own visible box.
    parentElement: { checkVisibility: () => true },
    matches: () => true,
    reload: async () => {
        ++reloads
        listeners.get('turbo:before-frame-render')({ target: badge, detail: { newFrame: { dataset: {} } } })
    },
}
context.badge = badge
vm.runInContext('active = true; frames.set(badge, { loading: false, failures: 0 });', context)
;(async () => {
    await vm.runInContext('poll(badge)', context)
    assert.equal(badge.src, '/_member_chat/unread')
    await vm.runInContext('poll(badge)', context)
    await vm.runInContext('poll(badge)', context)
    assert.equal(reloads, 2)
    assert.equal(badge.dataset.chatMode, 'full')
    assert.equal(scheduled, 3)
    document.hidden = true
    await vm.runInContext('poll(badge)', context)
    assert.equal(reloads, 2)
    document.hidden = false
    badge.parentElement.checkVisibility = () => false
    await vm.runInContext('poll(badge)', context)
    assert.equal(reloads, 2)
    console.log('PASS: empty standalone badge sets its source, reloads repeatedly in full mode and pauses for hidden tab/navigation')
})().catch(error => { console.error(error); process.exitCode = 1 })
