import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { runInNewContext } from 'node:vm'

const blade = readFileSync(new URL('../../resources/views/supplier/inventory/calendar.blade.php', import.meta.url), 'utf8')
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1]
const snapshot = { date: '2026-09-22', version: 7, available: 4, price: '100.00' }

function page(fetch) {
    const context = { fetch, URLSearchParams }
    runInNewContext(script, context)
    const state = context.inventoryCalendar({ roomId: 12, dataUrl: '/calendar', updateUrl: '/update', csrf: 'test-token' })
    state.$refs = { dayEditor: {
        open: false,
        showModal() { this.open = true },
        close() { this.open = false },
    } }
    state.days = [{ ...snapshot }]
    return state
}

test('opening and cancelling copies current values without changing inventory or making a request', () => {
    const state = page(() => { throw new Error('No network request expected') })
    state.edit(state.days[0])
    assert.equal(state.$refs.dayEditor.open, true)
    assert.equal(state.form.available, 4)
    assert.equal(state.form.price, '100.00')
    state.form.available = 9
    state.closeEditor()
    assert.equal(state.$refs.dayEditor.open, false)
    assert.equal(state.days[0].available, 4)
})

test('saving submits the room and version snapshot then refreshes the visible day', async () => {
    const requests = []
    const state = page(async (url, options) => {
        requests.push(url)
        if (url === '/update') {
            assert.equal(options.method, 'POST')
            assert.equal(options.headers['X-CSRF-TOKEN'], 'test-token')
            assert.deepEqual(JSON.parse(options.body), { room_id: 12, date: snapshot.date, version: 7, available: '8', price: '45.50' })
            return { ok: true, json: async () => ({ success: true }) }
        }
        return { ok: true, json: async () => [{ ...snapshot, version: 8, available: 8, price: '45.50' }] }
    })
    state.edit(state.days[0])
    state.form.available = '8'
    state.form.price = '45.50'
    await state.save()
    assert.equal(requests.length, 2)
    assert.equal(state.days[0].available, 8)
    assert.equal(state.$refs.dayEditor.open, false)
    assert.equal(state.busy, false)
    assert.match(state.notice, /Inventory saved/)
})

test('validation errors keep the dialog and draft open without changing the card', async () => {
    const state = page(async () => ({ ok: false, status: 422, json: async () => ({ errors: { price: ['Invalid price.'] } }) }))
    state.edit(state.days[0])
    state.form.price = -1
    await state.save()
    assert.equal(state.error, 'Invalid price.')
    assert.equal(state.$refs.dayEditor.open, true)
    assert.equal(state.form.price, -1)
    assert.equal(state.days[0].price, '100.00')
})

test('conflicts require an explicit reload of the current version before retrying', async () => {
    let writes = 0
    const state = page(async url => {
        if (url === '/update') {
            writes++
            return { ok: false, status: 409, json: async () => ({ message: 'Inventory changed. Reload before saving.' }) }
        }
        return { ok: true, json: async () => [{ ...snapshot, version: 8, available: 2 }] }
    })
    state.edit(state.days[0])
    await state.save()
    await state.save()
    assert.equal(writes, 1)
    assert.equal(state.conflict, true)
    assert.equal(state.$refs.dayEditor.open, true)
    await state.reloadDay()
    assert.equal(state.form.version, 8)
    assert.equal(state.form.available, 2)
    assert.equal(state.conflict, false)
})

test('a pending save prevents duplicate requests and dialog dismissal', async () => {
    let resolve, requests = 0
    const state = page(() => { requests++; return new Promise(done => { resolve = done }) })
    state.edit(state.days[0])
    const saving = state.save()
    await state.save()
    state.closeEditor()
    assert.equal(requests, 1)
    assert.equal(state.$refs.dayEditor.open, true)
    resolve({ ok: false, status: 500, json: async () => ({ message: 'Try again.' }) })
    await saving
    assert.equal(state.busy, false)
})

test('an old month response cannot overwrite a newer room selection', async () => {
    const pending = []
    const state = page(() => new Promise(resolve => pending.push(resolve)))
    const first = state.load()
    state.roomId = 13
    const second = state.load()
    pending[1]({ ok: true, json: async () => [{ ...snapshot, available: 9 }] })
    await second
    pending[0]({ ok: false, json: async () => ({ message: 'Old error' }) })
    await first
    assert.equal(state.days[0].available, 9)
    assert.equal(state.error, null)
    assert.equal(state.loading, false)
})
