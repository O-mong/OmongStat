import { mkdtemp, mkdir, cp, readFile, access, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'
const root = fileURLToPath(new URL('../', import.meta.url))
const plugin = join(root, 'wordpress_plugins/omongstat')
const manifest = JSON.parse(
  await readFile(join(plugin, 'assets/admin/.vite/manifest.json'), 'utf8'),
)
const entry = manifest['src/main.tsx']
for (const file of [entry.file, ...(entry.css || [])]) {
  await access(join(plugin, 'assets/admin', file))
}
const staging = await mkdtemp(join(tmpdir(), 'omongstat-zip-'))
try {
  const target = join(staging, 'omongstat')
  await mkdir(target)
  for (const path of ['omongstat.php', 'uninstall.php', 'includes', 'assets', 'bin']) {
    await cp(join(plugin, path), join(target, path), { recursive: true })
  }
  await mkdir(join(root, 'dist'), { recursive: true })
  const output = resolve(root, 'dist/omongstat.zip')
  await rm(output, { force: true })
  execFileSync('python3', [join(root, 'scripts/archive.py'), staging, output])
  console.log(output)
} finally {
  await rm(staging, { recursive: true, force: true })
}
