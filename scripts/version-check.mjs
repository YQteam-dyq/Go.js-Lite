import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const SEMVER = /^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/
const failures = []

const readText = relative => readFileSync(join(root, relative), 'utf8')
const readJson = relative => JSON.parse(readText(relative))
const fail = message => failures.push(message)

const expect = (condition, message) => {
  if (!condition) {
    fail(message)
  }
}

const manifest = readJson('version.json')
const version = typeof manifest.version === 'string' ? manifest.version.trim() : ''

expect(SEMVER.test(version), `version.json: "version" must be a semver string, got ${JSON.stringify(version)}`)

const packageJson = readJson('package.json')
expect(
  packageJson.version === version,
  `package.json: version "${packageJson.version}" does not match version.json "${version}"`,
)

const packageLock = readJson('package-lock.json')
expect(
  packageLock.version === version,
  `package-lock.json: root version "${packageLock.version}" does not match version.json "${version}"`,
)
expect(
  Boolean(packageLock.packages) && packageLock.packages[''] !== undefined,
  'package-lock.json: the root package entry "" is missing',
)
expect(
  Boolean(packageLock.packages) && packageLock.packages[''].version === version,
  `package-lock.json: packages[""].version "${packageLock.packages[''].version}" does not match version.json "${version}"`,
)

const derivedSources = [
  ['api.php', 'gojs_version()'],
  ['tests/bootstrap.php', 'gojs_version()'],
  ['shared/version.ts', 'version.json'],
]

for (const [relative, marker] of derivedSources) {
  const text = readText(relative)
  expect(text.includes(marker), `${relative}: expected the shared version source marker "${marker}"`)
  const literal = text.match(/[0-9]+\.[0-9]+\.[0-9]+/)
  expect(
    literal === null,
    `${relative}: found a hard-coded version literal "${literal && literal[0]}", read it from version.json instead`,
  )
}

for (const relative of ['README.md', 'README.zh-CN.md']) {
  const badge = readText(relative).match(/version-([0-9]+\.[0-9]+\.[0-9]+)-blue\.svg/)
  expect(badge !== null, `${relative}: the version badge is missing`)
  expect(
    badge !== null && badge[1] === version,
    `${relative}: the version badge shows "${badge && badge[1]}" but version.json says "${version}"`,
  )
}

const changelog = readText('CHANGELOG.md')
expect(
  changelog.includes(`## [${version}]`),
  `CHANGELOG.md: no "## [${version}]" section for the version in version.json`,
)

if (failures.length > 0) {
  for (const failure of failures) {
    console.error(`version-check: ${failure}`)
  }
  console.error(`version-check: ${failures.length} problem(s) found, the version must have a single source of truth`)
  process.exit(1)
}

console.log(`version-check: every version source agrees on ${version}`)
