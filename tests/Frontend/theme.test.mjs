import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { runInNewContext } from 'node:vm'

const blade = readFileSync(new URL('../../resources/views/layouts/partials/theme-init.blade.php', import.meta.url), 'utf8')
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1]

function eventHub() {
    return {
        listeners: {},
        addEventListener(name, callback) { (this.listeners[name] ??= []).push(callback) },
        dispatchEvent(event) { for (const callback of this.listeners[event.type] ?? []) callback(event) },
    }
}

function page(preference, storageBlocked = false) {
    let saved = preference
    const classes = new Set()
    const window = Object.assign(eventHub(), {
        localStorage: {
            getItem() { if (storageBlocked) throw new Error('Blocked'); return saved },
            setItem(key, value) { if (storageBlocked) throw new Error('Blocked'); saved = value },
        },
    })
    const document = Object.assign(eventHub(), {
        documentElement: { classList: { toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name) } } },
    })
    const context = { window, document, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail } } }
    runInNewContext(script, context)
    return {
        window, document, classes, context,
        saved: () => saved,
        swap(snapshotDark = false) {
            const callbacks = []
            document.dispatchEvent({ type: 'livewire:navigating', detail: { onSwap: callback => callbacks.push(callback) } })
            // Navigation replaces root attributes with server HTML or a cached history snapshot.
            classes.clear()
            if (snapshotDark) classes.add('dark')
            callbacks.forEach(callback => callback())
        },
    }
}

test('dark preference survives supplier navigation before the navigated event', () => {
    const state = page('dark')
    assert.equal(state.classes.has('dark'), true)
    state.swap()
    assert.equal(state.classes.has('dark'), true)
    assert.equal(state.window.__bookYourHotelTheme, 'dark')
    assert.equal(state.saved(), 'dark')
})

test('light preference survives navigation and stale dark history snapshots', () => {
    const state = page('light')
    state.swap()
    assert.equal(state.classes.has('dark'), false)
    state.swap(true)
    assert.equal(state.classes.has('dark'), false)
    assert.equal(state.window.__bookYourHotelTheme, 'light')
})

test('toggling persists across reloads and history uses the latest choice', () => {
    const state = page('light')
    state.window.toggleBookYourHotelTheme()
    state.swap(false)
    assert.equal(state.classes.has('dark'), true)
    assert.equal(page(state.saved()).classes.has('dark'), true)
    state.window.toggleBookYourHotelTheme()
    state.swap(true)
    assert.equal(state.classes.has('dark'), false)
    assert.equal(page(state.saved()).classes.has('dark'), false)
})

test('blocked storage keeps default dark and permits an in-memory theme choice', () => {
    const state = page(null, true)
    assert.equal(state.classes.has('dark'), true)
    state.window.toggleBookYourHotelTheme()
    state.swap(true)
    assert.equal(state.classes.has('dark'), false)
    assert.equal(state.window.__bookYourHotelTheme, 'light')
})

test('reexecuting the shared initializer does not add duplicate navigation listeners', () => {
    const state = page('dark')
    runInNewContext(script, state.context)
    assert.equal(state.document.listeners['livewire:navigating'].length, 1)
    assert.equal(state.document.listeners['livewire:navigated'].length, 1)
})
