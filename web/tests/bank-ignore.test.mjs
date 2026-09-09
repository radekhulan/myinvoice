import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { ref, computed } from 'vue'

// Execute the page's setup with API/lifecycle doubles, retaining Vue reactivity.
function setup(ignore, unmatch) {
  const source = readFileSync(new URL('../src/pages/bank/StatementDetail.vue', import.meta.url), 'utf8')
    .split('<script setup lang="ts">')[1].split('</script>')[0]
  const script = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
    transformers: { before: [context => node => ts.visitNode(node, function visit(n) {
      return ts.isImportDeclaration(n) ? undefined : ts.visitEachChild(n, visit, context)
    })] },
  }).outputText.replace(/export \{\};?/, '')
  return runInNewContext(`${script}\n({ statement, statusFilter, filteredTransactions, loading, ignoreTx, closeIgnore, confirmIgnore, ignoreTarget, ignoreNote, ignoreError, ignoring, unmatchTx, closeUnmatch, confirmUnmatch, unmatchTarget, unmatchError, unmatching })`, {
    ref, computed, onMounted() {}, useHotkey() {},
    useRoute: () => ({ params: { id: 1 } }), useRouter: () => ({}),
    useAuthStore: () => ({ canWrite: true }), useToast: () => ({}),
    useI18n: () => ({ t: key => key, locale: ref('cs') }),
    apiErrorMessage: e => e.message,
    bankApi: { ignore, unmatch, get() { throw new Error('Unexpected page reload') } },
  })
}

function seed(page) {
  page.statement.value = { id: 1, transactions: [{ id: 2, match_status: 'unmatched' }] }
  page.loading.value = false
  page.statusFilter.value = 'unmatched'
  return page.statement.value.transactions[0]
}

test('cancel does not call API; confirmation updates the row and filter without reload', async () => {
  const calls = []
  const page = setup(async (id, note) => { calls.push([id, note]); return { ignored: true, ignore_note: note } })
  const tx = seed(page)
  page.ignoreTx(tx)
  page.closeIgnore()
  assert.equal(calls.length, 0)
  assert.equal(tx.match_status, 'unmatched')
  page.ignoreTx(tx)
  page.ignoreNote.value = '  Test note  '
  await page.confirmIgnore()
  assert.deepEqual(calls, [[2, 'Test note']])
  assert.equal(tx.match_status, 'ignored')
  assert.equal(tx.ignore_note, 'Test note')
  assert.equal(page.filteredTransactions.value.length, 0)
  assert.equal(page.statusFilter.value, 'unmatched')
  assert.equal(page.loading.value, false)
  assert.equal(page.ignoreTarget.value, null)
})

test('pending request prevents duplicate submission; failure preserves note and row for retry', async () => {
  let reject, calls = 0
  const page = setup(() => { calls++; return new Promise((_, no) => { reject = no }) })
  const tx = seed(page)
  page.ignoreTx(tx)
  page.ignoreNote.value = 'Retry note'
  const pending = page.confirmIgnore()
  await page.confirmIgnore()
  page.closeIgnore()
  assert.equal(calls, 1)
  assert.equal(page.ignoreTarget.value, tx)
  reject(new Error('Test failure'))
  await pending
  assert.equal(tx.match_status, 'unmatched')
  assert.equal(page.ignoreNote.value, 'Retry note')
  assert.equal(page.ignoreError.value, 'Test failure')
  assert.equal(page.ignoring.value, false)
  assert.equal(page.filteredTransactions.value.length, 1)
})


test('unmatch confirmation clears linked invoices and updates count without reload', async () => {
  let calls = 0
  const page = setup(null, async () => { calls++ })
  const tx = seed(page)
  Object.assign(tx, { match_status: 'manual', matched_invoice_id: 8, matched_invoices: [{ invoice_id: 8 }] })
  page.statement.value.matched_count = 1
  page.statusFilter.value = 'manual'
  page.unmatchTx(tx)
  page.closeUnmatch()
  assert.equal(calls, 0)
  assert.equal(tx.match_status, 'manual')
  page.unmatchTx(tx)
  await page.confirmUnmatch()
  assert.equal(calls, 1)
  assert.equal(tx.match_status, 'unmatched')
  assert.equal(tx.matched_invoice_id, null)
  assert.equal(tx.matched_invoices.length, 0)
  assert.equal(page.statement.value.matched_count, 0)
  assert.equal(page.filteredTransactions.value.length, 0)
  assert.equal(page.loading.value, false)
  assert.equal(page.unmatchTarget.value, null)
})

test('unmatch failure keeps dialog and original state; pending request cannot repeat', async () => {
  let reject, calls = 0
  const page = setup(null, () => { calls++; return new Promise((_, no) => { reject = no }) })
  const tx = seed(page)
  tx.match_status = 'manual'
  page.statement.value.matched_count = 1
  page.unmatchTx(tx)
  const pending = page.confirmUnmatch()
  await page.confirmUnmatch()
  page.closeUnmatch()
  assert.equal(calls, 1)
  assert.equal(page.unmatchTarget.value, tx)
  reject(new Error('Test failure'))
  await pending
  assert.equal(tx.match_status, 'manual')
  assert.equal(page.statement.value.matched_count, 1)
  assert.equal(page.unmatchError.value, 'Test failure')
  assert.equal(page.unmatching.value, false)
})

test('returning an ignored transaction keeps matched count and clears ignore note', async () => {
  const page = setup(null, async () => {})
  const tx = seed(page)
  Object.assign(tx, { match_status: 'ignored', ignore_note: 'Test note' })
  page.statement.value.matched_count = 3
  page.unmatchTx(tx)
  await page.confirmUnmatch()
  assert.equal(tx.match_status, 'unmatched')
  assert.equal(tx.ignore_note, null)
  assert.equal(page.statement.value.matched_count, 3)
})
