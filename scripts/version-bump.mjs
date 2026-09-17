import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const SEMVER = /^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/

const next = (process.argv[2] || '').trim()

if (!SEMVER.test(next)) {
  process.stderr.write(`version-bump: usage: node scripts/version-bump.mjs <x.y.z>\n`)
  process.exit(1)
}

const readText = relative => readFileSync(join(root, relative), 'utf8')
const writeText = (relative, text) => writeFileSync(join(root, relative), text)

const manifestPath = 'version.json'
const manifest = JSON.parse(readText(manifestPath))
manifest.version = next
writeText(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`)

const packagePath = 'package.json'
let packageText = readText(packagePath)
packageText = packageText.replace(/("version":\s*")[^"]*(")/, `$1${next}$2`)
writeText(packagePath, packageText)

const lockPath = 'package-lock.json'
let lockText = readText(lockPath)
lockText = lockText.replace(/("name": "gojs-lite",\r?\n\s*"version": ")[^"]*(")/g, `$1${next}$2`)
writeText(lockPath, lockText)

for (const relative of ['README.md', 'README.zh-CN.md']) {
  let text = readText(relative)
  text = text.replace(/version-[0-9]+\.[0-9]+\.[0-9]+-blue\.svg/g, `version-${next}-blue.svg`)
  writeText(relative, text)
}

process.stdout.write(
  `version-bump: ${next} written to version.json, package.json, package-lock.json and the README badges\n`,
)
process.stdout.write(
  'version-bump: add the matching "## [<version>]" section to CHANGELOG.md and the docs before releasing\n',
)
