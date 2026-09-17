import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { dirname, resolve, join, relative } from 'node:path'
import { gzipSync } from 'node:zlib'

const root = process.cwd()

function print(line) {
  process.stdout.write((line === undefined ? '' : String(line)) + '\n')
}

function printError(line) {
  process.stderr.write(String(line) + '\n')
}

const dirIndex = process.argv.indexOf('--dir')
if (dirIndex !== -1 && !process.argv[dirIndex + 1]) {
  printError('Usage error: --dir requires a non-empty path')
  process.exit(1)
}
const distDir = resolve(root, dirIndex !== -1 ? process.argv[dirIndex + 1] : 'dist')
const budgetKb = Number(process.env.BUNDLE_BUDGET_KB || 180)
const budgetBytes = Math.round(budgetKb * 1024)
const strict = !process.argv.includes('--warn')

if (!existsSync(distDir)) {
  printError('Bundle report: dist directory not found at ' + distDir)
  process.exit(1)
}

function listFiles(dir) {
  const out = []
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) {
      out.push(...listFiles(full))
    } else {
      out.push(full)
    }
  }
  return out
}

function toPosix(p) {
  return p.split('\\').join('/')
}

function gzipSize(buffer) {
  return gzipSync(buffer, { level: 9 }).length
}

function formatKb(bytes) {
  return (bytes / 1024).toFixed(2)
}

function parseStaticSpecifiers(code) {
  const masked = code.replace(/import\s*\(/g, 'import (')
  const specs = new Set()
  const importRe = /\bimport\s*["']([^"']+)["']/g
  const fromRe = /\bfrom\s*["']([^"']+)["']/g
  let match
  while ((match = importRe.exec(masked)) !== null) specs.add(match[1])
  while ((match = fromRe.exec(masked)) !== null) specs.add(match[1])
  return [...specs]
}

function resolveFrom(importer, spec) {
  if (!spec.startsWith('./') && !spec.startsWith('../')) return null
  const target = resolve(dirname(importer), spec)
  const rel = toPosix(relative(distDir, target))
  if (rel.startsWith('..')) return null
  return existsSync(target) ? target : null
}

function collectClosure(entries) {
  const seen = new Set()
  const queue = [...entries]
  while (queue.length > 0) {
    const file = queue.pop()
    if (seen.has(file)) continue
    seen.add(file)
    let code = ''
    try {
      code = readFileSync(file, 'utf8')
    } catch {
      continue
    }
    for (const spec of parseStaticSpecifiers(code)) {
      const next = resolveFrom(file, spec)
      if (next && !seen.has(next)) queue.push(next)
    }
  }
  return [...seen]
}

const indexPath = join(distDir, 'index.html')
if (!existsSync(indexPath)) {
  printError('Bundle report: index.html not found in ' + distDir)
  process.exit(1)
}

const html = readFileSync(indexPath, 'utf8')
function resolveAsset(spec) {
  const clean = spec.replace(/^\/+/, '').split('?')[0]
  const direct = resolve(distDir, clean)
  if (existsSync(direct)) return direct
  const parts = clean.split('/')
  while (parts.length > 1) {
    parts.shift()
    const candidate = resolve(distDir, parts.join('/'))
    if (existsSync(candidate)) return candidate
  }
  return null
}

function collectAssets(pattern, attribute) {
  const files = []
  for (const tag of html.match(pattern) || []) {
    const value = attribute.exec(tag)
    if (!value) continue
    const file = resolveAsset(value[1])
    if (file) files.push(file)
  }
  return files
}

const entryScripts = collectAssets(/<script\b[^>]*>/g, /src="([^"]+)"/).filter((f) => f.endsWith('.js'))
const entryStyles = collectAssets(/<link\b[^>]*rel="stylesheet"[^>]*>/g, /href="([^"]+)"/).filter((f) => f.endsWith('.css'))

const allFiles = listFiles(distDir)
const allJs = allFiles.filter((f) => f.endsWith('.js'))
const allCss = allFiles.filter((f) => f.endsWith('.css'))

const initialJs = collectClosure(entryScripts)
const initialSet = new Set(initialJs)
const lazyJs = allJs.filter((f) => !initialSet.has(f))

function measure(files) {
  return files
    .map((file) => {
      const buffer = readFileSync(file)
      return {
        name: toPosix(relative(distDir, file)),
        raw: buffer.length,
        gzip: gzipSize(buffer),
      }
    })
    .sort((a, b) => b.gzip - a.gzip)
}

function sum(items) {
  return items.reduce((acc, item) => acc + item.gzip, 0)
}

const initialJsStats = measure(initialJs)
const initialCssStats = measure(entryStyles)
const lazyStats = measure(lazyJs)
const otherStats = measure(allCss.filter((f) => !entryStyles.includes(f)))

const initialJsBytes = sum(initialJsStats)
const initialCssBytes = sum(initialCssStats)
const initialTotal = initialJsBytes + initialCssBytes

function printTable(title, items) {
  if (items.length === 0) return
  print('')
  print(title)
  for (const item of items) {
    print('  ' + formatKb(item.gzip).padStart(9) + ' KB gz  ' + formatKb(item.raw).padStart(10) + ' KB raw  ' + item.name)
  }
}

print('Bundle report for ' + toPosix(relative(root, distDir) || 'dist'))
print('')
print('  Initial JS   ' + formatKb(initialJsBytes) + ' KB gzip (' + initialJsStats.length + ' file(s), budget ' + budgetKb + ' KB)')
print('  Initial CSS  ' + formatKb(initialCssBytes) + ' KB gzip (' + initialCssStats.length + ' file(s))')
print('  Initial total ' + formatKb(initialTotal) + ' KB gzip')
print('  Lazy chunks  ' + formatKb(sum(lazyStats)) + ' KB gzip (' + lazyStats.length + ' file(s))')

printTable('Initial JS chunks (counted against the budget):', initialJsStats)
printTable('Initial CSS:', initialCssStats)
printTable('Lazy chunks (loaded on demand):', lazyStats.slice(0, 25))
printTable('Deferred CSS:', otherStats)

const report = {
  budgetKb,
  initial: {
    jsBytes: initialJsBytes,
    cssBytes: initialCssBytes,
    totalBytes: initialTotal,
    files: initialJsStats,
    cssFiles: initialCssStats,
  },
  lazy: {
    bytes: sum(lazyStats),
    files: lazyStats,
  },
  deferredCss: otherStats,
  withinBudget: initialJsBytes <= budgetBytes,
}

writeFileSync(join(distDir, 'bundle-report.json'), JSON.stringify(report, null, 2) + '\n')

print('')
if (initialJsBytes <= budgetBytes) {
  print('Budget OK: initial JS ' + formatKb(initialJsBytes) + ' KB gzip is within ' + budgetKb + ' KB.')
  process.exit(0)
}

const over = formatKb(initialJsBytes - budgetBytes)
printError('Budget exceeded: initial JS is ' + formatKb(initialJsBytes) + ' KB gzip, ' + over + ' KB over the ' + budgetKb + ' KB budget.')
process.exit(strict ? 1 : 0)
