import test from 'node:test'
import assert from 'node:assert/strict'
import { cp, mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { validatePlugin } from '../scripts/validate-package.mjs'

async function fixture(t) {
  const root = await mkdtemp(join(tmpdir(), 'omongstat-package-test-'))
  t.after(() => rm(root, { recursive: true, force: true }))
  const plugin = join(root, 'omongstat')
  await cp(new URL('../wordpress_plugins/omongstat/', import.meta.url), plugin, { recursive: true })
  return { root, plugin }
}

test('accepts the built plugin', async (t) => {
  const { plugin } = await fixture(t)
  assert.ok((await validatePlugin(plugin)).includes('includes/security.php'))
})

test('rejects external file and directory symlinks', async (t) => {
  const { root, plugin } = await fixture(t)
  const external = join(root, 'outside.txt')
  await writeFile(external, 'synthetic-secret')
  await symlink(external, join(plugin, 'assets/leak.txt'))
  await assert.rejects(validatePlugin(plugin), /Symlink rejected/)
  await rm(join(plugin, 'assets/leak.txt'))
  await symlink(root, join(plugin, 'assets/outside'))
  await assert.rejects(validatePlugin(plugin), /Symlink rejected/)
})

test('rejects manifest traversal and unexpected runtime files', async (t) => {
  const { plugin } = await fixture(t)
  const manifestPath = join(plugin, 'assets/admin/.vite/manifest.json')
  const original = await readFile(manifestPath, 'utf8')
  const manifest = JSON.parse(original)
  manifest['src/main.tsx'].file = '../../../omongstat.php'
  await writeFile(manifestPath, JSON.stringify(manifest))
  await assert.rejects(validatePlugin(plugin), /Unexpected admin asset path/)
  await writeFile(manifestPath, original)
  await writeFile(join(plugin, 'includes/.env'), 'synthetic-secret')
  await assert.rejects(validatePlugin(plugin), /Unexpected runtime file/)
})
