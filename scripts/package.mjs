import { mkdtemp, mkdir, copyFile, rm, rename } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'
import { validatePlugin } from './validate-package.mjs'

const root = fileURLToPath(new URL('../', import.meta.url))
const plugin = join(root, 'wordpress_plugins/omongstat')
const files = await validatePlugin(plugin)
const staging = await mkdtemp(join(tmpdir(), 'omongstat-zip-'))

try {
  const target = join(staging, 'omongstat')
  for (const name of files) {
    const destination = join(target, name)
    await mkdir(dirname(destination), { recursive: true })
    await copyFile(join(plugin, name), destination)
  }
  await validatePlugin(target)
  await mkdir(join(root, 'dist'), { recursive: true })
  const temporaryZip = join(root, 'dist', `.omongstat-${process.pid}.zip`)
  const output = join(root, 'dist/omongstat.zip')
  try {
    execFileSync('python3', [join(root, 'scripts/archive.py'), staging, temporaryZip])
    await rename(temporaryZip, output)
  } finally {
    await rm(temporaryZip, { force: true })
  }
  console.log(output)
} finally {
  await rm(staging, { recursive: true, force: true })
}
