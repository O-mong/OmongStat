import { lstat, readFile, readdir, realpath } from 'node:fs/promises'
import { isAbsolute, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const runtimeFiles = [
  'omongstat.php',
  'uninstall.php',
  'bin/import.php',
  ...[
    'security',
    'install',
    'migration',
    'maintenance',
    'collect',
    'rest-stats',
    'admin',
    'import',
  ].map((name) => `includes/${name}.php`),
  'assets/js/collector.js',
  'assets/admin/.vite/manifest.json',
]

export async function validatePlugin(directory) {
  const root = resolve(directory)
  if ((await lstat(root)).isSymbolicLink()) throw new Error('Plugin root must not be a symlink.')
  const canonicalRoot = await realpath(root)

  async function validateFile(name) {
    if (
      typeof name !== 'string' ||
      isAbsolute(name) ||
      name.includes('\\') ||
      name.includes('\0')
    ) {
      throw new Error('Invalid package path.')
    }
    let path = root
    for (const part of name.split('/')) {
      if (!part || part === '.' || part === '..') throw new Error('Invalid package path segment.')
      path = join(path, part)
      if ((await lstat(path)).isSymbolicLink()) throw new Error(`Symlink rejected: ${name}`)
    }
    const resolved = relative(canonicalRoot, await realpath(path))
    if (resolved.startsWith('..') || isAbsolute(resolved) || !(await lstat(path)).isFile()) {
      throw new Error(`File outside package or not a regular file: ${name}`)
    }
  }

  await validateFile('assets/admin/.vite/manifest.json')
  const manifest = JSON.parse(
    await readFile(join(root, 'assets/admin/.vite/manifest.json'), 'utf8'),
  )
  if (!manifest || !manifest['src/main.tsx']) throw new Error('Missing admin entry in manifest.')
  const allowed = new Set(runtimeFiles)
  for (const entry of Object.values(manifest)) {
    if (!entry || typeof entry.file !== 'string' || !Array.isArray(entry.css ?? [])) {
      throw new Error('Invalid admin manifest entry.')
    }
    for (const asset of [entry.file, ...(entry.css ?? [])]) {
      if (typeof asset !== 'string' || !/^assets\/[a-zA-Z0-9_-]+\.(js|css)$/.test(asset)) {
        throw new Error('Unexpected admin asset path.')
      }
      allowed.add(`assets/admin/${asset}`)
    }
  }
  for (const name of allowed) await validateFile(name)

  async function inspectTree(folder) {
    const absolute = join(root, folder)
    if ((await lstat(absolute)).isSymbolicLink()) throw new Error(`Symlink rejected: ${folder}`)
    for (const entry of await readdir(absolute, { withFileTypes: true })) {
      const name = `${folder}/${entry.name}`
      if (entry.isSymbolicLink()) throw new Error(`Symlink rejected: ${name}`)
      if (entry.isDirectory()) await inspectTree(name)
      else if (!entry.isFile() || !allowed.has(name))
        throw new Error(`Unexpected runtime file: ${name}`)
    }
  }
  for (const folder of ['includes', 'assets', 'bin']) await inspectTree(folder)
  return [...allowed]
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    await validatePlugin(process.argv[2])
    console.log('Plugin package validation passed.')
  } catch (error) {
    console.error(`Package validation failed: ${error.message}`)
    process.exitCode = 1
  }
}
