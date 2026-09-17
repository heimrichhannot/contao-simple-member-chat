const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const source = fs.readFileSync(process.env.CHAT_CLIENT_SOURCE || path.join(__dirname, '../../assets/js/member_chat.js'), 'utf8')

function harness(interval = 30000, kind = 'messages') {
    let now = 0
    let sequence = 0
    const timers = new Map()
    const intervals = new Map()
    const observers = []
    const events = { document: new Map(), window: new Map() }
    const requests = []
    const scrollListeners = []
    let response = async () => ({ ok: true, status: 204 })
    const frame = {
        id: 'chat-messages', isConnected: true, src: 'https://example.test/messages',
        dataset: { chatPoll: 'messages', chatMode: 'incremental', chatPollInterval: String(interval), chatPollMaxInterval: '240000', chatAfter: '12' },
        hasAttribute: () => false, checkVisibility: () => true,
        querySelectorAll: () => [], querySelector: () => null,
        scrollTop: 0, scrollHeight: 100, clientHeight: 100,
        addEventListener: (name, callback) => { if (name === 'scroll') scrollListeners.push(callback) },
    }
    if (kind === 'badge') {
        frame.id = 'chat-unread'
        frame.dataset.chatPoll = 'badge'
        frame.dataset.chatMode = 'full'
        frame.parentElement = { checkVisibility: () => true }
        frame.checkVisibility = () => false
        frame.hasAttribute = name => name === 'complete'
        frame.reload = async () => { requests.push({ at: now }); await response() }
    }
    const document = {
        hidden: false, readyState: 'loading', documentElement: {},
        addEventListener: (name, callback) => events.document.set(name, callback),
        querySelectorAll: selector => selector === 'turbo-frame[data-chat-poll]' ? [frame] : [],
        getElementById: id => id === frame.id ? frame : null,
    }
    class Observer {
        constructor(callback) { this.callback = callback; this.connected = false; observers.push(this) }
        observe() { this.connected = true }
        disconnect() { this.connected = false }
    }
    const context = vm.createContext({
        document, console, AbortController, URL,
        Date: class extends Date { static now() { return now } },
        window: { Turbo: {}, addEventListener: (name, callback) => events.window.set(name, callback) },
        MutationObserver: Observer, ResizeObserver: Observer,
        requestAnimationFrame() {}, cancelAnimationFrame() {},
        setTimeout: (callback, delay) => { const id = ++sequence; timers.set(id, { callback, at: now + delay }); return id },
        clearTimeout: id => timers.delete(id),
        setInterval: callback => { const id = ++sequence; intervals.set(id, callback); return id },
        clearInterval: id => intervals.delete(id),
        fetch: (url, options) => { requests.push({ at: now, url, options }); return response() },
    })
    vm.runInContext(source, context)
    const emit = (name, detail = {}) => (events.document.get(name) || events.window.get(name))?.(detail)
    const flush = async () => { for (let i = 0; i < 20; ++i) await Promise.resolve() }
    async function advance(milliseconds) {
        const end = now + milliseconds
        let count = 0
        while (true) {
            const next = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0]
            if (!next) break
            assert.ok(++count < 100, 'no zero-delay polling loop')
            now = next[1].at
            timers.delete(next[0])
            next[1].callback()
            await flush()
        }
        now = end
        await flush()
    }
    emit('DOMContentLoaded')
    return { frame, document, requests, timers, intervals, observers, scrollListeners, emit, advance, flush, context,
        respond: callback => { response = callback },
        visibility: hidden => { document.hidden = hidden; emit('visibilitychange') },
    }
}

const tests = {
    async restore() {
        for (const persisted of [false, true]) {
            const h = harness(4000)
            await h.advance(9000)
            assert.equal(h.requests.length, 2)
            h.emit('turbo:before-cache')
            await h.advance(10000)
            assert.equal(h.requests.length, 2, 'teardown must stop polling')
            h.emit('pageshow', { persisted })
            await h.advance(4000)
            assert.equal(h.requests.length, 3, 'pageshow must resume without turbo:load')
            const observerCount = h.observers.length
            h.emit('pageshow', { persisted }); h.emit('turbo:render'); h.emit('turbo:load')
            assert.equal(h.observers.length, observerCount, 'active restarts must reuse observers')
            assert.equal(h.intervals.size, 1)
            assert.equal(h.timers.size, 1)
            assert.equal(h.scrollListeners.length, 1, 'restored elements must not gain duplicate listeners')
            await h.advance(4000)
            assert.equal(h.requests.length, 4)
        }
    },
    async remaining() {
        const h = harness(30000, 'badge')
        for (let i = 0; i < 5; ++i) {
            await h.advance(4000); h.visibility(true)
            await h.advance(1000); h.visibility(false)
        }
        await h.advance(4999)
        assert.equal(h.requests.length, 0)
        await h.advance(1)
        assert.deepEqual(h.requests.map(request => request.at), [30000], 'toggles must preserve the original deadline')
        await h.advance(5000); h.visibility(true)
        await h.advance(35000)
        assert.equal(h.requests.length, 1, 'hidden documents must not poll')
        h.visibility(false); await h.advance(0)
        assert.deepEqual(h.requests.map(request => request.at), [30000, 70000], 'overdue polling must resume immediately')
    },
    async lifecycle() {
        const h = harness(4000)
        for (const signal of ['visibilitychange', 'turbo:render', 'turbo:load']) {
            h.emit('turbo:before-cache')
            // Late frame events must not recreate polling while stopped.
            h.emit('turbo:frame-load', { target: { id: 'other' } })
            assert.equal(h.timers.size, 0)
            h.emit(signal)
            await h.advance(4000)
        }
        assert.equal(h.requests.length, 3)
        h.emit('pagehide')
        await h.advance(12000)
        assert.equal(h.requests.length, 3)
        h.emit('pageshow', { persisted: true })
        await h.advance(4000)
        assert.equal(h.requests.length, 4)
        assert.equal(h.scrollListeners.length, 1)
    },
    async backoff() {
        const h = harness()
        h.respond(async () => { throw new Error('offline') })
        await h.advance(30000)
        await h.advance(10000); h.visibility(true)
        await h.advance(10000); h.visibility(false)
        await h.advance(39999)
        assert.equal(h.requests.length, 1)
        await h.advance(1)
        assert.equal(h.requests[1].at, 90000)
        await h.advance(120000)
        await h.advance(240000)
        await h.advance(240000)
        assert.deepEqual(h.requests.map(request => request.at), [30000, 90000, 210000, 450000, 690000])
        h.respond(async () => ({ ok: true, status: 204 }))
        await h.advance(240000)
        await h.advance(30000)
        assert.equal(h.requests.at(-1).at, 960000, 'success resets backoff')
        h.frame.checkVisibility = () => false
        await h.advance(90000)
        assert.equal(h.requests.at(-1).at, 960000)
        h.frame.checkVisibility = () => true
        h.frame.hasAttribute = name => name === 'busy'
        await h.advance(30000)
        assert.equal(h.requests.at(-1).at, 960000)
    },
    async pending() {
        const h = harness(4000)
        let resolve
        h.respond(() => new Promise(done => { resolve = done }))
        await h.advance(4000)
        h.visibility(true); h.visibility(false)
        await h.advance(4000)
        assert.equal(h.requests.length, 1, 'pending requests must not overlap')
        h.emit('turbo:before-cache')
        assert.equal(h.requests[0].options.signal.aborted, true)
        h.emit('pageshow', { persisted: true })
        const timer = [...h.timers.keys()][0]
        resolve({ ok: true, status: 204 }); await h.flush()
        assert.equal([...h.timers.keys()][0], timer, 'old completion must not reschedule the restored frame')
        h.respond(async () => ({ ok: true, status: 204 }))
        await h.advance(4000)
        assert.equal(h.requests.length, 2)
        assert.equal(h.frame.dataset.chatAfter, '12')
    },
}
;(async () => {
    for (const [name, test] of Object.entries(tests)) {
        if (process.argv[2] && process.argv[2] !== name) continue
        await test()
        console.log(`PASS: ${name}`)
    }
})().catch(error => { console.error(error); process.exitCode = 1 })
