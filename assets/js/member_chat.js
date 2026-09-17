import "../css/member_chat.css"

const Turbo = window.Turbo
if (!Turbo) {
    console.error('[member-chat] window.Turbo is missing. Activate "huh_ux_turbo_encore" or "huh_ux_turbo_encore_no_drive" on this page.')
}

const frames = new Map()
let observer
let searchTimer
let active = false
let focusCompose = false
let focusMute = false
let resizeObserver
let loadObserver
let viewportFrame
let timeTimer
let batchId = 0
const batches = new Map()
const observedButtons = new WeakSet()

// Turbo's renderStreamMessage returns void; wait for every wrapped stream render.
function renderStreams(body) {
    const template = document.createElement("template")
    template.innerHTML = body
    const streams = [...template.content.querySelectorAll("turbo-stream")]
    if (!streams.length) return Promise.resolve()
    const id = String(++batchId)
    streams.forEach(stream => { stream.dataset.chatBatch = id })
    return new Promise((resolve, reject) => {
        batches.set(id, { remaining: streams.length, resolve, reject })
        Turbo.renderStreamMessage(template.innerHTML)
    })
}

function atBottom(frame) {
    return frame.scrollHeight - frame.scrollTop - frame.clientHeight <= 24
}

function updateScrollState(frame) {
    const bottom = atBottom(frame)
    frame.dataset.chatAtBottom = String(bottom)
    if (bottom) frame.dataset.chatHasNew = "false"
}

function anchor(frame) {
    if (!frame) return null
    const top = frame.getBoundingClientRect().top
    const item = [...frame.querySelectorAll("[data-chat-message-id]")].find(item => item.getBoundingClientRect().bottom > top)
    return item ? { id: item.id, offset: item.getBoundingClientRect().top - top } : null
}

function restoreAnchor(frame, saved) {
    const item = saved && document.getElementById(saved.id)
    if (item) frame.scrollTop += item.getBoundingClientRect().top - frame.getBoundingClientRect().top - saved.offset
}

function formatTimes() {
    document.querySelectorAll('.member-chat time[datetime]').forEach(element => {
        const timestamp = Date.parse(element.dateTime)
        if (!Number.isFinite(timestamp)) return
        const language = element.closest('[lang]')?.lang || document.documentElement.lang || 'en'
        const seconds = (timestamp - Date.now()) / 1000
        let text
        if (Math.abs(seconds) < 604800) {
            const unit = Math.abs(seconds) < 60 ? 'second' : Math.abs(seconds) < 3600 ? 'minute' : Math.abs(seconds) < 86400 ? 'hour' : 'day'
            const divisor = { second: 1, minute: 60, hour: 3600, day: 86400 }[unit]
            text = new Intl.RelativeTimeFormat(language, { numeric: 'auto' }).format(Math.round(seconds / divisor), unit)
        } else {
            text = new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeStyle: 'short' }).format(timestamp)
        }
        if (element.textContent !== text) element.textContent = text
        element.title = new Intl.DateTimeFormat(language, { dateStyle: 'full', timeStyle: 'short' }).format(timestamp)
    })
}

function resizeViewport() {
    cancelAnimationFrame(viewportFrame)
    viewportFrame = requestAnimationFrame(() => {
        document.querySelectorAll('.member-chat--with-conversation').forEach(root => {
            const frame = root.querySelector('#chat-messages')
            const bottom = frame?.dataset.chatAtBottom === 'true'
            // CSS chooses the layout, including project breakpoint overrides.
            const singleColumn = !root.querySelector('.member-chat__sidebar')?.checkVisibility()
            if (window.visualViewport && singleColumn) {
                const top = Math.max(0, root.getBoundingClientRect().top - window.visualViewport.offsetTop)
                root.style.setProperty('--member-chat-viewport-height', `${Math.max(0, window.visualViewport.height - top)}px`)
            } else {
                root.style.removeProperty('--member-chat-viewport-height')
            }
            if (bottom) scrollBottom()
        })
    })
}

function reconcileMessages(frame) {
    const items = [...frame.querySelectorAll('[data-chat-message-id]')].sort((a, b) => Number(a.dataset.chatMessageId) - Number(b.dataset.chatMessageId))
    const order = []
    const more = frame.querySelector('[data-chat-load-more]')
    if (more) order.push(more)
    let previousDay
    items.forEach(item => {
        const separator = document.getElementById(`chat-day-${item.dataset.chatMessageId}`)
        if (separator) {
            separator.hidden = item.dataset.chatMessageDay === previousDay
            order.push(separator)
        }
        order.push(item)
        previousDay = item.dataset.chatMessageDay
    })
    order.forEach((item, index) => {
        if (frame.children[index] !== item) frame.insertBefore(item, frame.children[index] || null)
    })
}

async function loadMore(button) {
    const frame = button.closest('[data-chat-poll]')
    const state = frames.get(frame)
    if (!state || state.loading || button.disabled || frame.hasAttribute('busy')) return
    const restoreFocus = document.activeElement === button
    state.loading = true
    state.abort = new AbortController()
    button.disabled = true
    const live = frame.getAttribute('aria-live')
    try {
        const response = await fetch(button.dataset.chatUrl, { headers: { Accept: 'text/vnd.turbo-stream.html' }, credentials: 'same-origin', signal: state.abort.signal })
        if (!response.ok || !response.headers.get('Content-Type')?.includes('text/vnd.turbo-stream.html')) throw new Error('History request failed')
        const body = await response.text()
        if (!active || !frame.isConnected) return
        frame.setAttribute('aria-live', 'off')
        frame.dataset.chatLoadedBefore = 'true'
        frame.dataset.chatMode = 'incremental'
        await renderStreams(body)
        frame.dataset.chatBefore = response.headers.get('X-Chat-Before') || ''
        if (restoreFocus) (document.getElementById(button.id) || frame).focus({ preventScroll: true })
        // Stream rendering is complete; do not hold the lock for a repaint.
        state.failures = 0
    } catch (error) {
        if (error.name !== 'AbortError') {
            state.failures += 1
            // Keep the button available for a deliberate retry, without an auto-load loop.
            loadObserver?.unobserve(button)
        }
    } finally {
        if (live === null) frame.removeAttribute('aria-live')
        else frame.setAttribute('aria-live', live)
        state.loading = false
        button.disabled = false
        schedule(frame)
        discover()
    }
}

function schedule(frame) {
    const state = frames.get(frame)
    if (!state) return
    clearTimeout(state.timer)
    if (!active || document.hidden || !frame.isConnected) return
    const interval = Number(frame.dataset.chatPollInterval)
    const maximum = Number(frame.dataset.chatPollMaxInterval)
    state.timer = setTimeout(() => poll(frame), Math.min(interval * 2 ** Math.min(state.failures, 16), maximum))
}

async function poll(frame) {
    const state = frames.get(frame)
    if (!state) return
    if (document.hidden || frame.hasAttribute("busy") || !frame.checkVisibility() || state.loading) {
        schedule(frame)
        return
    }
    state.loading = true
    state.abort = new AbortController()
    try {
        if (frame.dataset.chatMode !== "incremental") {
            await frame.reload()
            if (!frame.hasAttribute("complete")) throw new Error("Frame reload failed")
            frame.dataset.chatMode = "incremental"
        } else {
            const messages = frame.dataset.chatPoll === "messages"
            let count
            do {
                const url = new URL(frame.src, document.baseURI)
                url.searchParams.set(messages ? "after" : "since", messages ? frame.dataset.chatAfter || "0" : frame.dataset.chatSince || "0")
                if (!messages && frame.dataset.chatFingerprint) url.searchParams.set("fingerprint", frame.dataset.chatFingerprint)
                const response = await fetch(url, {
                    headers: { Accept: "text/vnd.turbo-stream.html" },
                    credentials: "same-origin",
                    signal: state.abort.signal,
                })
                if (!response.ok) throw new Error(`Poll failed: ${response.status}`)
                if (response.status === 204) break
                if (!response.headers.get("Content-Type")?.includes("text/vnd.turbo-stream.html")) throw new Error("Expected Turbo Stream")
                const body = await response.text()
                if (!active || !frame.isConnected) return
                await renderStreams(body)
                count = Number(response.headers.get("X-Chat-Count"))
                if (messages) {
                    // Only delivered poll windows advance this cursor. A concurrent
                    // send may have a higher ID than incoming messages still unseen.
                    frame.dataset.chatAfter = String(Math.max(Number(frame.dataset.chatAfter || 0), Number(response.headers.get("X-Chat-After"))))
                } else {
                    frame.dataset.chatFingerprint = response.headers.get("X-Chat-Fingerprint") || ""
                    frame.dataset.chatSince = String(Math.max(Number(frame.dataset.chatSince || 0), Number(response.headers.get("X-Chat-Since"))))
                }
            } while (messages && count >= Number(frame.dataset.chatPageSize) && !document.hidden && frame.checkVisibility())
        }
        state.failures = 0
    } catch (error) {
        if (error.name !== "AbortError") state.failures += 1
    } finally {
        state.loading = false
        schedule(frame)
        discover()
    }
}

function scrollBottom() {
    const frame = document.getElementById("chat-messages")
    if (frame) {
        frame.scrollTop = frame.scrollHeight
        updateScrollState(frame)
    }
}

function discover() {
    for (const [frame, state] of frames) {
        if (!frame.isConnected) {
            clearTimeout(state.timer)
            state.abort?.abort()
            frames.delete(frame)
        }
    }
    document.querySelectorAll("turbo-frame[data-chat-poll]").forEach(frame => {
        if (frames.has(frame)) return
        frames.set(frame, { timer: null, failures: 0, loading: false, abort: null })
        schedule(frame)
        resizeObserver?.observe(frame)
        if (frame.id === "chat-messages") {
            frame.addEventListener('scroll', () => updateScrollState(frame), { passive: true })
            reconcileMessages(frame)
            scrollBottom()
        }
    })
    document.querySelectorAll('[data-chat-load-more]').forEach(button => {
        if (frames.get(button.closest('[data-chat-poll]'))?.loading || !loadObserver || observedButtons.has(button) || button.closest('[data-chat-auto-load="false"]')) return
        observedButtons.add(button)
        loadObserver.observe(button)
    })
    if (focusCompose) {
        const textarea = document.querySelector("#chat-compose textarea")
        if (textarea && !textarea.disabled) {
            textarea.focus({ preventScroll: true })
            focusCompose = false
        }
    }
}

function start() {
    if (!Turbo) return
    active = true
    observer?.disconnect()
    resizeObserver?.disconnect()
    resizeObserver = new ResizeObserver(resizeViewport)
    document.querySelectorAll('.member-chat, .member-chat__header, #chat-compose').forEach(element => resizeObserver.observe(element))
    loadObserver?.disconnect()
    loadObserver = typeof IntersectionObserver === 'undefined' ? null : new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting || !entry.target.checkVisibility() || entry.target.closest('[data-chat-auto-load="false"]')) return
            const frame = entry.target.closest('[data-chat-poll]')
            if (frames.get(frame)?.loading || frame.hasAttribute('busy')) {
                // Retry observation after the poll or lazy frame render completes.
                loadObserver.unobserve(entry.target)
                observedButtons.delete(entry.target)
                return
            }
            loadMore(entry.target)
        })
    })
    // Existing buttons must be re-observed on a Turbo lifecycle restart.
    document.querySelectorAll('[data-chat-load-more]').forEach(button => observedButtons.delete(button))
    observer = new MutationObserver(() => { discover(); resizeViewport() })
    observer.observe(document.documentElement, { childList: true, subtree: true })
    discover()
    formatTimes()
    resizeViewport()
    clearInterval(timeTimer)
    timeTimer = setInterval(formatTimes, 30000)
}

window.visualViewport?.addEventListener('resize', resizeViewport)
window.visualViewport?.addEventListener('scroll', resizeViewport)
window.addEventListener('resize', resizeViewport)
// Capture non-bubbling scroll events from the document and scrolling ancestors.
document.addEventListener('scroll', resizeViewport, { capture: true, passive: true })

document.addEventListener("turbo:load", start)
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start)
else start()

document.addEventListener("turbo:before-cache", () => {
    active = false
    observer?.disconnect()
    resizeObserver?.disconnect()
    loadObserver?.disconnect()
    cancelAnimationFrame(viewportFrame)
    clearInterval(timeTimer)
    for (const batch of batches.values()) batch.resolve()
    batches.clear()
    clearTimeout(searchTimer)
    for (const state of frames.values()) {
        clearTimeout(state.timer)
        state.abort?.abort()
    }
    frames.clear()
    focusCompose = false
    focusMute = false
})

document.addEventListener("visibilitychange", () => {
    for (const frame of frames.keys()) schedule(frame)
})

document.addEventListener("turbo:before-frame-render", event => {
    const frame = event.target
    if (frame.id === "chat-search") {
        const query = frame.querySelector('input[name=q]')?.value.trim()
        const incomingQuery = event.detail.newFrame.querySelector('input[name=q]')?.value.trim()
        // A response to a previous query must not restore results after erasing it.
        if (query !== incomingQuery) {
            event.detail.render = async () => {}
            return
        }
    }
    if (frame.id === "chat-search" && frame.contains(document.activeElement)) {
        const focusedId = document.activeElement.id
        const render = event.detail.render
        event.detail.render = async (current, incoming) => {
            await render(current, incoming)
            document.getElementById(focusedId)?.focus({ preventScroll: true })
        }
    }
    if (!frame.matches?.("[data-chat-poll]")) return
    const incoming = event.detail.newFrame
    // Turbo keeps the existing frame element; copy the server window cursor.
    for (const key of ["chatAfter", "chatSince", "chatPageSize", "chatFingerprint"]) {
        if (incoming.dataset[key] !== undefined) frame.dataset[key] = incoming.dataset[key]
    }
    frame.dataset.chatMode = "incremental"
})

document.addEventListener("turbo:frame-load", event => {
    if (event.target.id === "chat-messages") scrollBottom()
    if (event.target.id === "chat-compose" && focusCompose) discover()
    discover()
    formatTimes()
    resizeViewport()
})

document.addEventListener("turbo:before-stream-render", event => {
    const stream = event.target
    const target = stream.getAttribute("target") || ''
    if (!target.startsWith("chat-") && !stream.dataset.chatBatch) return
    const render = event.detail.render
    event.detail.render = async element => {
        const frame = document.getElementById('chat-messages')
        const touchesMessages = target === 'chat-messages' || target === 'chat-more-messages'
        const saved = touchesMessages ? anchor(frame) : null
        const bottom = frame && atBottom(frame)
        const prepend = stream.getAttribute('action') === 'prepend'
        const incoming = [...stream.templateContent.querySelectorAll('[data-chat-message-id]')]
        const hasNew = incoming.some(item => !document.getElementById(item.id))
        const muteFocused = target === 'chat-mute' && (focusMute || document.getElementById('chat-mute')?.contains(document.activeElement))
        const moreFocused = target.startsWith('chat-more-') && document.activeElement?.id === target
        try {
            if (target === "chat-compose") focusCompose = true
            await render(element)
            formatTimes()
            if (target === "chat-conversations") {
                const list = document.getElementById(target)
                const items = [...list.querySelectorAll("[data-chat-uuid]")]
                items.sort((a, b) => Number(b.dataset.chatLastMessageAt) - Number(a.dataset.chatLastMessageAt) || b.dataset.chatUuid.localeCompare(a.dataset.chatUuid))
                items.forEach((item, index) => {
                    if (list.children[index] !== item) list.insertBefore(item, list.children[index] || null)
                })
            }
            if (touchesMessages && frame) {
                reconcileMessages(frame)
                if (!prepend && target === 'chat-messages' && bottom) scrollBottom()
                else restoreAnchor(frame, saved)
                if (!prepend && hasNew && !bottom) frame.dataset.chatHasNew = 'true'
                updateScrollState(frame)
            }
            if (target === "chat-compose") {
                discover()
                scrollBottom()
                resizeObserver?.observe(document.getElementById('chat-compose'))
            }
            if (muteFocused) {
                document.getElementById('chat-mute-button')?.focus({ preventScroll: true })
                focusMute = false
            }
            if (moreFocused) {
                const replacement = document.getElementById(target)
                if (replacement) replacement.focus({ preventScroll: true })
                else document.getElementById(target === 'chat-more-messages' ? 'chat-messages' : 'chat-conversations')?.focus({ preventScroll: true })
            }
            resizeViewport()
            const batch = batches.get(stream.dataset.chatBatch)
            if (batch && --batch.remaining === 0) {
                batches.delete(stream.dataset.chatBatch)
                batch.resolve()
            }
        } catch (error) {
            batches.get(stream.dataset.chatBatch)?.reject(error)
            batches.delete(stream.dataset.chatBatch)
            throw error
        }
    }
})

document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-chat-load-more]')
    if (button) loadMore(button)
    if (event.target.closest?.('[data-chat-new-messages]')) {
        scrollBottom()
        document.getElementById('chat-messages')?.focus({ preventScroll: true })
    }
})

document.addEventListener('submit', event => {
    if (event.target.closest?.('#chat-mute')) focusMute = event.target.contains(document.activeElement)
}, true)

document.addEventListener("turbo:submit-start", event => {
    if (event.target.matches("[data-chat-compose]")) focusCompose = true
})

document.addEventListener("turbo:submit-end", event => {
    if (event.target.matches("[data-chat-compose]")) {
        focusCompose = true
        requestAnimationFrame(discover)
        if (event.detail.success) requestAnimationFrame(scrollBottom)
    }
})

// A successful contact start navigates the page; errors remain in chat-search.
document.addEventListener("turbo:before-fetch-response", event => {
    const response = event.detail.fetchResponse
    if (event.target.matches?.("[data-chat-open]") && response.redirected && response.succeeded) {
        event.preventDefault()
        Turbo.visit(response.location.href)
    }
})

document.addEventListener("keydown", event => {
    if (event.target.matches?.("#chat-compose textarea") && event.key === "Enter" && !event.shiftKey && !event.isComposing && !matchMedia("(pointer: coarse)").matches) {
        event.preventDefault()
        event.target.form.requestSubmit()
    }
})

document.addEventListener("input", event => {
    if (!event.target.matches?.("[data-chat-search] input[name=q]")) return
    clearTimeout(searchTimer)
    const input = event.target
    const form = input.form
    if ([...input.value.trim()].length < Number(form.dataset.chatSearchMinLength)) {
        document.querySelector('[data-chat-search-results]')?.replaceChildren()
        return
    }
    searchTimer = setTimeout(() => { if (form.isConnected) form.requestSubmit() }, 300)
})
