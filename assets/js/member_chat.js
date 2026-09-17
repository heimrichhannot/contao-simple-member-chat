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
                Turbo.renderStreamMessage(body)
                count = Number(response.headers.get("X-Chat-Count"))
                if (messages) {
                    // Only delivered poll windows advance this cursor. A concurrent
                    // send may have a higher ID than incoming messages still unseen.
                    frame.dataset.chatAfter = String(Math.max(Number(frame.dataset.chatAfter || 0), Number(response.headers.get("X-Chat-After"))))
                } else {
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
    }
}

function scrollBottom() {
    const frame = document.getElementById("chat-messages")
    if (frame) frame.scrollTop = frame.scrollHeight
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
        if (frame.id === "chat-messages") scrollBottom()
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
    observer = new MutationObserver(discover)
    observer.observe(document.documentElement, { childList: true, subtree: true })
    discover()
}

document.addEventListener("turbo:load", start)
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start)
else start()

document.addEventListener("turbo:before-cache", () => {
    active = false
    observer?.disconnect()
    clearTimeout(searchTimer)
    for (const state of frames.values()) {
        clearTimeout(state.timer)
        state.abort?.abort()
    }
    frames.clear()
    focusCompose = false
})

document.addEventListener("visibilitychange", () => {
    for (const frame of frames.keys()) schedule(frame)
})

document.addEventListener("turbo:before-frame-render", event => {
    const frame = event.target
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
    for (const key of ["chatAfter", "chatSince", "chatPageSize"]) {
        if (incoming.dataset[key] !== undefined) frame.dataset[key] = incoming.dataset[key]
    }
    frame.dataset.chatMode = "incremental"
})

document.addEventListener("turbo:frame-load", event => {
    if (event.target.id === "chat-messages") scrollBottom()
    if (event.target.id === "chat-compose" && focusCompose) discover()
})

document.addEventListener("turbo:before-stream-render", event => {
    const stream = event.target
    const target = stream.getAttribute("target")
    if (!["chat-compose", "chat-conversations", "chat-messages"].includes(target)) return
    const render = event.detail.render
    event.detail.render = async element => {
        if (target === "chat-compose") focusCompose = true
        await render(element)
        if (target === "chat-conversations") {
            const frame = document.getElementById(target)
            const items = [...frame.querySelectorAll("[data-chat-uuid]")]
            // Read/mute changes do not change activity order. UUID is a stable
            // public tie-breaker without exposing internal conversation IDs.
            items.sort((a, b) => Number(b.dataset.chatLastMessageAt) - Number(a.dataset.chatLastMessageAt) || b.dataset.chatUuid.localeCompare(a.dataset.chatUuid))
            items.forEach(item => frame.append(item))
        }
        if (target === "chat-compose") {
            discover()
            scrollBottom()
        }
    }
})

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
    if (event.target.matches?.("#chat-compose textarea") && event.key === "Enter" && !event.shiftKey && !event.isComposing && !matchMedia("(pointer: coarse)").matches && navigator.maxTouchPoints === 0) {
        event.preventDefault()
        event.target.form.requestSubmit()
    }
})

document.addEventListener("input", event => {
    if (!event.target.matches?.("[data-chat-search] input[name=q]")) return
    clearTimeout(searchTimer)
    const input = event.target
    const form = input.form
    if ([...input.value.trim()].length < Number(form.dataset.chatSearchMinLength)) return
    searchTimer = setTimeout(() => { if (form.isConnected) form.requestSubmit() }, 300)
})
