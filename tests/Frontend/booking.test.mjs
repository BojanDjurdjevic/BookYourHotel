import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { runInNewContext } from 'node:vm'

const blade = readFileSync(new URL('../../resources/views/booking/show.blade.php', import.meta.url), 'utf8')
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1]

function page(fetch, overrides = {}) {
    const context = {
        fetch,
        URLSearchParams,
        document: { querySelector: () => ({ getAttribute: () => 'test-token' }) },
        window: { location: { href: '' }, scrollTo() {} },
    }
    runInNewContext(script, context)
    const state = context.bookingPage({ hotelId: 1, availabilityUrl: '/availability', storeUrl: '/booking', ...overrides })
    state.testWindow = context.window
    return state
}

test('editing a verification challenge restores guest details but requires fresh room selection', () => {
    const state = page(undefined, { editGuest: { guest_name: 'Guest Van Test', guest_email: 'guest@example.test', guest_phone: '123' } })
    assert.equal(state.guest.firstName, 'Guest')
    assert.equal(state.guest.lastName, 'Van Test')
    assert.equal(state.guest.email, 'guest@example.test')
    assert.equal(state.guest.phone, '123')
    assert.equal(state.bookingItems.length, 0)
    assert.equal(state.hasCurrentAvailability(), false)
})

test('guest submission proceeds to verification and account verification errors open the native notice', async () => {
    for (const response of [
        { ok: true, json: async () => ({ redirect: '/booking/verify-email' }) },
        { ok: false, json: async () => ({ verification_url: '/verify-email', message: 'Verify your email.' }) },
    ]) {
        const state = selectedPage(async () => response)
        await state.submitBooking()
        assert.equal(state.testWindow.location.href, response.ok ? '/booking/verify-email' : '/verify-email')
        assert.equal(state.submitError, null)
        assert.equal(state.submitting, false)
    }
})

function selectedPage(fetch) {
    const state = page(fetch)
    state.checkIn = '2026-09-15'
    state.checkOut = '2026-09-17'
    state.results = { check_in: state.checkIn, check_out: state.checkOut }
    state.rooms = [{ id: 1 }]
    state.nights = 2
    state.bookingItems = [{ room_id: 1, board_type_id: 1, quantity: 1, adults: 1, children: 0, room_total: 200 }]
    state.selectedBoards = { 1: 1 }
    state.selectedQuantities = { 1: 1 }
    state.step = 'review'
    return state
}

test('changing either date invalidates results, selected rooms and prices', () => {
    for (const field of ['checkIn', 'checkOut']) {
        const state = selectedPage()
        state[field] = '2026-09-18'
        state.resetAvailability()
        assert.equal(state.results, null)
        assert.equal(state.rooms.length, 0)
        assert.equal(state.bookingItems.length, 0)
        assert.equal(Object.keys(state.selectedBoards).length, 0)
        assert.equal(Object.keys(state.selectedQuantities).length, 0)
        assert.equal(state.nights, 0)
        assert.equal(state.bookingTotal(), 0)
        assert.equal(state.searched, false)
        assert.equal(state.step, 'rooms')
    }
})

test('an old availability response cannot replace results for new dates', async () => {
    const requests = []
    const state = selectedPage(() => new Promise(resolve => requests.push(resolve)))
    const oldSearch = state.searchAvailability()
    state.checkOut = '2026-09-18'
    state.resetAvailability()
    const newSearch = state.searchAvailability()

    requests[1]({ ok: true, json: async () => ({ check_in: state.checkIn, check_out: state.checkOut, nights: 3, rooms: [] }) })
    await newSearch
    requests[0]({ ok: true, json: async () => ({ check_in: state.checkIn, check_out: '2026-09-17', nights: 2, rooms: [{ id: 1, available: 3 }] }) })
    await oldSearch

    assert.equal(state.nights, 3)
    assert.equal(state.results.check_out, '2026-09-18')
    assert.equal(state.rooms.length, 0)
    assert.equal(state.loading, false)
})

test('a late failed search cannot clear a newer successful search', async () => {
    let rejectOld
    const state = selectedPage(() => new Promise((resolve, reject) => { rejectOld = reject }))
    const oldSearch = state.searchAvailability()
    state.resetAvailability()
    state.results = { check_in: state.checkIn, check_out: state.checkOut }
    state.nights = 2
    rejectOld(new Error('Old network failure'))
    await oldSearch
    assert.equal(state.error, null)
    assert.equal(state.nights, 2)
})

test('repeated submit sends one request and exposes a server error for retry', async () => {
    let resolveRequest
    let calls = 0
    const state = selectedPage(() => {
        calls++
        return new Promise(resolve => { resolveRequest = resolve })
    })
    const first = state.submitBooking()
    assert.equal(state.submitting, true)
    await state.submitBooking()
    assert.equal(calls, 1)

    resolveRequest({ ok: false, json: async () => ({ message: 'Not enough availability.' }) })
    await first
    assert.equal(state.submitError, 'Not enough availability.')
    assert.equal(state.submitting, false)
})

test('submit rejects stale dates even before a UI reset, and rejects an empty selection', async () => {
    let calls = 0
    const state = selectedPage(() => { calls++ })
    state.checkOut = '2026-09-20'
    await state.submitBooking()
    assert.equal(calls, 0)
    assert.ok(state.submitError)
    assert.equal(state.step, 'rooms')

    state.resetAvailability()
    await state.submitBooking()
    assert.equal(calls, 0)
})
