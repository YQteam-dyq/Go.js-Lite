import type {
  BootstrapData,
  DashboardData,
  SystemData,
  FileEntry,
  FileContent,
  ProcessInfo,
  CronJob,
  DbConnection,
  DbTable,
  DbColumn,
  SqlExecResponse,
  PhpInfoData,
  FileOperationRequest,
  Capabilities,
} from '@shared/types'

const MOCK_DELAY = 250

let _authenticated = false
let _installed = false
let _csrfToken = 'mock-csrf-' + Math.random().toString(36).slice(2, 12)
let _accessToken = 'mock-access-' + Math.random().toString(36).slice(2, 16)

const mockCapabilities: Capabilities = {
  disk: true,
  mysql: true,
  terminal: false,
  processes: true,
  cron: true,
  zip: true,
  targz: true,
  gd: true,
  openBasedir: false,
  disabledFunctions: [],
  phpVersion: '8.2.15',
  sapi: 'fpm-fcgi',
  maxUpload: 67108864,
  maxPost: 134217728,
  memoryLimit: 268435456,
}

let _fileStore: Map<string, FileEntry[]> = new Map()
let _fileStoreInitialized = false

function delay<T>(data: T, ms = MOCK_DELAY): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(data), ms))
}

function ensureFileStoreInitialized() {
  if (_fileStoreInitialized) return
  _fileStoreInitialized = true

  const now = Date.now()
  const generateFiles = (dir: string): FileEntry[] => {
    const depth = dir === '/' ? 0 : dir.split('/').filter(Boolean).length
    if (depth > 3) return []

    const dirs = depth === 0
      ? ['public_html', 'private', 'logs', 'tmp', 'backup']
      : depth === 1 && dir === '/public_html'
      ? ['assets', 'css', 'js', 'images', 'vendor']
      : ['subdir1', 'subdir2']

    const files = depth === 0
      ? ['.htaccess', '.user.ini']
      : depth === 1 && dir === '/public_html'
      ? ['index.php', 'config.php', 'functions.php', 'README.md', 'composer.json', 'style.css']
      : ['file1.txt', 'file2.php', 'image.jpg', 'script.js']

    const items: FileEntry[] = [
      ...dirs.map((name, i) => ({
        name,
        path: (dir === '/' ? '' : dir) + '/' + name,
        type: 'dir' as const,
        size: 4096,
        mtime: now - i * 86400000,
        perms: '0755',
        readable: true,
        writable: true,
      })),
      ...files.map((name, i) => ({
        name,
        path: (dir === '/' ? '' : dir) + '/' + name,
        type: 'file' as const,
        size: 1024 * (i + 3) * (depth + 1),
        mtime: now - (i + 5) * 3600000,
        perms: name.startsWith('.') ? '0644' : '0664',
        readable: true,
        writable: !name.startsWith('.ht'),
      })),
    ]
    return items
  }

  const seedDirs = ['/', '/public_html', '/private', '/logs', '/tmp', '/backup', '/public_html/assets', '/public_html/css', '/public_html/js', '/public_html/images', '/public_html/vendor']
  for (const dir of seedDirs) {
    _fileStore.set(dir, generateFiles(dir))
  }
}

function normalizePath(dir: string): string {
  if (!dir || dir === '') return '/'
  if (!dir.startsWith('/')) dir = '/' + dir
  if (dir.endsWith('/') && dir.length > 1) dir = dir.slice(0, -1)
  return dir
}

function validateFileName(name: string): { valid: boolean; error?: string } {
  if (!name || !name.trim()) {
    return { valid: false, error: '文件名不能为空' }
  }
  const trimmed = name.trim()
  if (/[\\/:*?"<>|]/.test(trimmed)) {
    return { valid: false, error: '文件名不能包含非法字符：\\ / : * ? " < > |' }
  }
  if (trimmed === '.' || trimmed === '..') {
    return { valid: false, error: '文件名不能为 . 或 ..' }
  }
  return { valid: true }
}

export const mockApi = {
  async bootstrap(): Promise<BootstrapData> {
    return delay({
      authenticated: _authenticated,
      installed: _installed,
      csrfToken: _csrfToken,
      backendVersion: '0.1.0-mock',
      frontendVersion: '0.1.0',
      capabilities: mockCapabilities,
      user: _authenticated ? { username: 'admin' } : undefined,
      accessToken: _installed ? _accessToken : undefined,
    } as unknown as BootstrapData)
  },

  async login(password: string): Promise<{ token: string }> {
    if (!password) throw new Error('密码不能为空')
    _authenticated = true
    _csrfToken = 'mock-csrf-' + Math.random().toString(36).slice(2, 12)
    return delay({ token: 'mock-token-' + Math.random().toString(36).slice(2, 12) })
  },

  async logout(): Promise<void> {
    _authenticated = false
    return delay(undefined)
  },

  async install(_password: string): Promise<BootstrapData> {
    _installed = true
    _authenticated = true
    _csrfToken = 'mock-csrf-' + Math.random().toString(36).slice(2, 12)
    _accessToken = 'mock-access-' + Math.random().toString(36).slice(2, 16)
    return delay({
      authenticated: true,
      installed: true,
      csrfToken: _csrfToken,
      capabilities: mockCapabilities,
      user: { username: 'admin' },
      backendVersion: '0.1.0',
      frontendVersion: '0.1.0',
      accessToken: _accessToken,
    })
  },

  async changePassword(): Promise<void> {
    return delay(undefined)
  },

  async getDashboard(): Promise<DashboardData> {
    const now = Date.now()
    return delay({
      phpVersion: '8.2.15',
      sapi: 'fpm-fcgi',
      webServer: 'Apache/2.4.57',
      hostname: 'shared-hosting.example.com',
      timezone: 'Asia/Shanghai',
      now,
      diskTotal: 10737418240,
      diskFree: 7516192768,
      diskUsed: 3221225472,
      rootPath: '/home/user/public_html',
      fileCount: 1247,
      totalSize: 524288000,
      maxUpload: 67108864,
      maxPost: 134217728,
      memoryLimit: 268435456,
      recentFiles: [
        { name: 'index.php', path: '/public_html/index.php', type: 'file', size: 4096, mtime: now - 3600000, perms: '0644', readable: true, writable: true },
        { name: 'config.php', path: '/public_html/config.php', type: 'file', size: 2048, mtime: now - 7200000, perms: '0644', readable: true, writable: true },
        { name: 'style.css', path: '/public_html/css/style.css', type: 'file', size: 8192, mtime: now - 86400000, perms: '0644', readable: true, writable: true },
        { name: 'app.js', path: '/public_html/js/app.js', type: 'file', size: 16384, mtime: now - 86400000 * 2, perms: '0644', readable: true, writable: true },
        { name: 'error_log', path: '/logs/error_log', type: 'file', size: 102400, mtime: now - 300000, perms: '0600', readable: true, writable: true },
      ],
    })
  },

  async getPhpInfo(): Promise<PhpInfoData> {
    return delay({
      version: '8.2.15',
      sapi: 'fpm-fcgi',
      iniFile: '/etc/php/8.2/fpm/php.ini',
      loadedExtensions: [
        'Core', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash',
        'iconv', 'json', 'mbstring', 'mysqli', 'mysqlnd', 'openssl', 'pcre',
        'PDO', 'pdo_mysql', 'pdo_sqlite', 'Phar', 'posix', 'readline',
        'Reflection', 'session', 'SimpleXML', 'sockets', 'SPL', 'sqlite3',
        'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib',
      ],
      coreIni: {
        'max_execution_time': '30',
        'max_input_time': '60',
        'memory_limit': '256M',
        'error_reporting': 'E_ALL',
        'display_errors': 'Off',
        'log_errors': 'On',
        'upload_max_filesize': '64M',
        'post_max_size': '128M',
        'date.timezone': 'Asia/Shanghai',
        'opcache.enable': '1',
        'opcache.memory_consumption': '128',
        'session.save_handler': 'files',
        'session.save_path': '/var/lib/php/sessions',
        'session.gc_maxlifetime': '1440',
        'expose_php': 'Off',
      },
      env: {
        'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'APP_ENV': 'production',
        'HOME': '/var/www',
      },
      server: {
        'SERVER_SOFTWARE': 'Apache/2.4.57 (Debian)',
        'DOCUMENT_ROOT': '/home/user/public_html',
        'SERVER_NAME': 'shared-hosting.example.com',
        'SERVER_ADDR': '10.0.0.123',
        'SERVER_PORT': '443',
        'REQUEST_SCHEME': 'https',
        'GATEWAY_INTERFACE': 'CGI/1.1',
        'SERVER_PROTOCOL': 'HTTP/1.1',
      },
    })
  },

  async getSystem(): Promise<SystemData> {
    return delay({
      diskTotal: 10737418240,
      diskFree: 7516192768,
      diskUsed: 3221225472,
      loadAverage: [0.42, 0.58, 0.61],
      uptime: 86400 * 15 + 3600 * 7,
      serverAddr: '10.0.0.123',
      serverName: 'shared-hosting.example.com',
    })
  },

  async listFiles(dir: string): Promise<FileEntry[]> {
    ensureFileStoreInitialized()
    const normalizedDir = normalizePath(dir)
    const files = _fileStore.get(normalizedDir) || []
    return delay([...files])
  },

  async createFile(dir: string, name: string): Promise<FileEntry> {
    ensureFileStoreInitialized()
    const normalizedDir = normalizePath(dir)
    const validation = validateFileName(name)
    if (!validation.valid) {
      throw new Error(validation.error)
    }
    const trimmedName = name.trim()
    const existing = _fileStore.get(normalizedDir) || []
    if (existing.some((f) => f.name === trimmedName)) {
      throw new Error('同名文件已存在')
    }
    const newFile: FileEntry = {
      name: trimmedName,
      path: normalizedDir === '/' ? '/' + trimmedName : normalizedDir + '/' + trimmedName,
      type: 'file',
      size: 0,
      mtime: Date.now(),
      perms: '0664',
      readable: true,
      writable: true,
    }
    _fileStore.set(normalizedDir, [newFile, ...existing])
    return delay(newFile)
  },

  async createDir(dir: string, name: string): Promise<FileEntry> {
    ensureFileStoreInitialized()
    const normalizedDir = normalizePath(dir)
    const validation = validateFileName(name)
    if (!validation.valid) {
      throw new Error(validation.error)
    }
    const trimmedName = name.trim()
    const existing = _fileStore.get(normalizedDir) || []
    if (existing.some((f) => f.name === trimmedName)) {
      throw new Error('同名目录已存在')
    }
    const newDir: FileEntry = {
      name: trimmedName,
      path: normalizedDir === '/' ? '/' + trimmedName : normalizedDir + '/' + trimmedName,
      type: 'dir',
      size: 4096,
      mtime: Date.now(),
      perms: '0755',
      readable: true,
      writable: true,
    }
    _fileStore.set(normalizedDir, [newDir, ...existing])
    const newDirPath = normalizedDir === '/' ? '/' + trimmedName : normalizedDir + '/' + trimmedName
    if (!_fileStore.has(newDirPath)) {
      _fileStore.set(newDirPath, [])
    }
    return delay(newDir)
  },

  async deleteFile(path: string): Promise<void> {
    ensureFileStoreInitialized()
    const normalizedPath = normalizePath(path)
    const parentDir = normalizedPath.substring(0, normalizedPath.lastIndexOf('/')) || '/'
    const fileName = normalizedPath.substring(normalizedPath.lastIndexOf('/') + 1)
    const files = _fileStore.get(parentDir) || []
    const updated = files.filter((f) => f.name !== fileName)
    _fileStore.set(parentDir, updated)
    return delay(undefined)
  },

  async deleteFiles(paths: string[]): Promise<void> {
    ensureFileStoreInitialized()
    for (const path of paths) {
      const normalizedPath = normalizePath(path)
      const parentDir = normalizedPath.substring(0, normalizedPath.lastIndexOf('/')) || '/'
      const fileName = normalizedPath.substring(normalizedPath.lastIndexOf('/') + 1)
      const files = _fileStore.get(parentDir) || []
      const updated = files.filter((f) => f.name !== fileName)
      _fileStore.set(parentDir, updated)
    }
    return delay(undefined)
  },

  async renameFile(path: string, newName: string): Promise<FileEntry> {
    ensureFileStoreInitialized()
    const validation = validateFileName(newName)
    if (!validation.valid) {
      throw new Error(validation.error)
    }
    const trimmedName = newName.trim()
    const normalizedPath = normalizePath(path)
    const parentDir = normalizedPath.substring(0, normalizedPath.lastIndexOf('/')) || '/'
    const oldName = normalizedPath.substring(normalizedPath.lastIndexOf('/') + 1)
    const files = _fileStore.get(parentDir) || []
    if (files.some((f) => f.name === trimmedName && f.name !== oldName)) {
      throw new Error('同名文件已存在')
    }
    const updated = files.map((f) => {
      if (f.name === oldName) {
        const newPath = parentDir === '/' ? '/' + trimmedName : parentDir + '/' + trimmedName
        return { ...f, name: trimmedName, path: newPath, mtime: Date.now() }
      }
      return f
    })
    _fileStore.set(parentDir, updated)
    const newFile = updated.find((f) => f.name === trimmedName)!
    return delay(newFile)
  },

  async uploadFile(dir: string, file: { name: string; size: number }): Promise<FileEntry> {
    ensureFileStoreInitialized()
    const normalizedDir = normalizePath(dir)
    const validation = validateFileName(file.name)
    if (!validation.valid) {
      throw new Error(validation.error)
    }
    const existing = _fileStore.get(normalizedDir) || []
    let finalName = file.name
    let counter = 1
    while (existing.some((f) => f.name === finalName)) {
      const dotIndex = file.name.lastIndexOf('.')
      if (dotIndex > 0) {
        finalName = file.name.slice(0, dotIndex) + ' (' + counter + ')' + file.name.slice(dotIndex)
      } else {
        finalName = file.name + ' (' + counter + ')'
      }
      counter++
    }
    const newFile: FileEntry = {
      name: finalName,
      path: normalizedDir === '/' ? '/' + finalName : normalizedDir + '/' + finalName,
      type: 'file',
      size: file.size,
      mtime: Date.now(),
      perms: '0664',
      readable: true,
      writable: true,
    }
    _fileStore.set(normalizedDir, [newFile, ...existing])
    return delay(newFile)
  },

  async fileOperation(data: FileOperationRequest): Promise<void> {
    ensureFileStoreInitialized()
    switch (data.action) {
      case 'create_file':
        await this.createFile(data.path.substring(0, data.path.lastIndexOf('/')) || '/', data.path.substring(data.path.lastIndexOf('/') + 1))
        break
      case 'create_dir':
        await this.createDir(data.path.substring(0, data.path.lastIndexOf('/')) || '/', data.path.substring(data.path.lastIndexOf('/') + 1))
        break
      case 'delete':
        await this.deleteFile(data.path)
        break
      case 'rename':
        if (data.target) await this.renameFile(data.path, data.target)
        break
      default:
        break
    }
    return delay(undefined)
  },

  simulateUploadProgress(
    onProgress: (percent: number) => void,
    totalDuration = 1500,
  ): Promise<void> {
    return new Promise((resolve) => {
      const startTime = Date.now()
      const step = () => {
        const elapsed = Date.now() - startTime
        const progress = Math.min(100, (elapsed / totalDuration) * 100)
        onProgress(progress)
        if (progress < 100) {
          requestAnimationFrame(step)
        } else {
          resolve()
        }
      }
      requestAnimationFrame(step)
    })
  },

  async getFileContent(path: string): Promise<FileContent> {
    const isPhp = path.endsWith('.php')
    const isJs = path.endsWith('.js')
    const isCss = path.endsWith('.css')
    const isMd = path.endsWith('.md')
    const isImg = path.endsWith('.jpg') || path.endsWith('.png')

    if (isImg) {
      return delay({
        type: 'image',
        content: '',
        size: 102400,
        mime: 'image/jpeg',
      })
    }

    const content = isPhp
      ? `<?php\n/**\n * ${path.split('/').pop()}\n * Demo file for preview\n */\n\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/vendor/autoload.php';\n\n$app = new App();\n$app->run();\n`
      : isJs
      ? `// ${path.split('/').pop()}\n// Demo JavaScript file\n\nfunction init() {\n  console.log('Hello from Go.js');\n}\n\ndocument.addEventListener('DOMContentLoaded', init);\n`
      : isCss
      ? `/* ${path.split('/').pop()} */\n/* Demo stylesheet */\n\nbody {\n  font-family: system-ui, sans-serif;\n  margin: 0;\n  padding: 0;\n}\n\n.container {\n  max-width: 1200px;\n  margin: 0 auto;\n}\n`
      : isMd
      ? `# ${path.split('/').pop()}\n\n## 简介\n\n这是一个演示文件，用于预览文件编辑器。\n\n## 功能\n\n- 文件浏览\n- 代码编辑\n- 语法高亮\n`
      : `这是 ${path} 的内容\n\n演示文件 - Go.js 预览版\n`

    return delay({
      type: 'text',
      content,
      size: content.length,
      mime: 'text/plain',
      lines: content.split('\n').length,
    })
  },

  async saveFile(): Promise<void> {
    return delay(undefined)
  },

  async getProcesses(): Promise<ProcessInfo[]> {
    return delay([
      { pid: 1, name: 'init', cmdline: '/sbin/init', cpu: 0.0, mem: 0.1 },
      { pid: 123, name: 'apache2', cmdline: '/usr/sbin/apache2 -k start', cpu: 1.2, mem: 2.3 },
      { pid: 124, name: 'apache2', cmdline: '/usr/sbin/apache2 -k start', cpu: 0.8, mem: 1.9 },
      { pid: 125, name: 'apache2', cmdline: '/usr/sbin/apache2 -k start', cpu: 2.1, mem: 2.5 },
      { pid: 256, name: 'mysqld', cmdline: '/usr/sbin/mysqld', cpu: 5.3, mem: 8.7 },
      { pid: 512, name: 'php-fpm', cmdline: 'php-fpm: pool www', cpu: 3.4, mem: 4.2 },
      { pid: 513, name: 'php-fpm', cmdline: 'php-fpm: pool www', cpu: 1.1, mem: 2.0 },
      { pid: 1024, name: 'sshd', cmdline: '/usr/sbin/sshd -D', cpu: 0.0, mem: 0.3 },
      { pid: 2048, name: 'cron', cmdline: '/usr/sbin/cron -f', cpu: 0.0, mem: 0.1 },
    ])
  },

  async getCron(): Promise<CronJob[]> {
    return delay([
      { minute: '0', hour: '2', day: '*', month: '*', weekday: '*', command: 'php /home/user/cron/backup.php', raw: '0 2 * * * php /home/user/cron/backup.php' },
      { minute: '*/15', hour: '*', day: '*', month: '*', weekday: '*', command: 'php /home/user/cron/queue.php', raw: '*/15 * * * * php /home/user/cron/queue.php' },
      { minute: '30', hour: '3', day: '*', month: '*', weekday: '0', command: 'php /home/user/cron/weekly-report.php', raw: '30 3 * * 0 php /home/user/cron/weekly-report.php' },
    ])
  },

  async listDbConnections(): Promise<DbConnection[]> {
    return delay([
      { id: 'default', name: '主数据库', host: 'localhost', port: 3306, username: 'user_main', database: 'user_maindb' },
      { id: 'forum', name: '论坛数据库', host: 'localhost', port: 3306, username: 'user_forum', database: 'user_forum' },
    ])
  },

  async addDbConnection(data: Omit<DbConnection, 'id'>): Promise<DbConnection> {
    return delay({ ...data, id: 'conn-' + Date.now() })
  },

  async updateDbConnection(): Promise<void> {
    return delay(undefined)
  },

  async deleteDbConnection(): Promise<void> {
    return delay(undefined)
  },

  async listDatabases(): Promise<string[]> {
    return delay(['information_schema', 'user_maindb', 'user_forum'])
  },

  async listTables(): Promise<DbTable[]> {
    return delay([
      { name: 'users', engine: 'InnoDB', rows: 15420, size: 2097152, collation: 'utf8mb4_unicode_ci', comment: '用户表' },
      { name: 'posts', engine: 'InnoDB', rows: 89234, size: 10485760, collation: 'utf8mb4_unicode_ci', comment: '文章表' },
      { name: 'comments', engine: 'InnoDB', rows: 234567, size: 15728640, collation: 'utf8mb4_unicode_ci', comment: '评论表' },
      { name: 'categories', engine: 'MyISAM', rows: 42, size: 65536, collation: 'utf8mb4_unicode_ci', comment: '分类表' },
      { name: 'tags', engine: 'InnoDB', rows: 1280, size: 131072, collation: 'utf8mb4_unicode_ci', comment: '标签表' },
      { name: 'settings', engine: 'InnoDB', rows: 89, size: 32768, collation: 'utf8mb4_unicode_ci', comment: '设置表' },
      { name: 'sessions', engine: 'Memory', rows: 3421, size: 524288, collation: 'utf8mb4_bin', comment: '会话表' },
      { name: 'logs', engine: 'InnoDB', rows: 567890, size: 52428800, collation: 'utf8mb4_unicode_ci', comment: '日志表' },
    ])
  },

  async getStructure(): Promise<DbColumn[]> {
    return delay([
      { name: 'id', type: 'bigint(20) unsigned', nullable: false, key: 'PRI', default: null, extra: 'auto_increment' },
      { name: 'username', type: 'varchar(50)', nullable: false, key: 'UNI', default: null, extra: '' },
      { name: 'email', type: 'varchar(100)', nullable: false, key: 'UNI', default: null, extra: '' },
      { name: 'password_hash', type: 'varchar(255)', nullable: false, key: '', default: null, extra: '' },
      { name: 'created_at', type: 'datetime', nullable: false, key: '', default: 'CURRENT_TIMESTAMP', extra: '' },
      { name: 'updated_at', type: 'datetime', nullable: true, key: '', default: null, extra: 'on update CURRENT_TIMESTAMP' },
      { name: 'status', type: 'tinyint(1)', nullable: false, key: 'MUL', default: '1', extra: '' },
      { name: 'bio', type: 'text', nullable: true, key: '', default: null, extra: '' },
    ])
  },

  async execSql(sql: string): Promise<SqlExecResponse> {
    const isSelect = /^select/i.test(sql.trim())
    if (isSelect) {
      return delay({
        results: [{
          success: true,
          statement: sql,
          rows: [
            { id: 1, username: 'admin', email: 'admin@example.com', status: 1, created_at: '2024-01-01 00:00:00' },
            { id: 2, username: 'user1', email: 'user1@example.com', status: 1, created_at: '2024-01-02 12:30:00' },
            { id: 3, username: 'user2', email: 'user2@example.com', status: 1, created_at: '2024-01-03 15:45:00' },
            { id: 4, username: 'user3', email: 'user3@example.com', status: 0, created_at: '2024-01-04 09:20:00' },
            { id: 5, username: 'user4', email: 'user4@example.com', status: 1, created_at: '2024-01-05 14:10:00' },
          ],
          affectedRows: 0,
        }],
        executionTime: 0.023,
      } as unknown as SqlExecResponse)
    }
    return delay({
      results: [{
        success: true,
        statement: sql,
        rows: [],
        affectedRows: 1,
      }],
      executionTime: 0.005,
    } as unknown as SqlExecResponse)
  },
}
