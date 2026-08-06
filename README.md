# Go.js Lite — Lightweight PHP Shared Hosting Control Panel

> A lightweight server management panel built specifically for PHP shared hosting. **Does not occupy your web root**. Mobile-friendly.

**English** (primary) · [Full Chinese Translation (全文中文)](README.zh-CN.md)

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)](https://php.net)
[![React](https://img.shields.io/badge/React-18-61dafb.svg)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178c6.svg)](https://www.typescriptlang.org)

---

## 🚀 Quick Start

### For Users (Deployment)

Download the latest `gojs-lite-VERSION.zip` from [Releases](https://github.com/YQteam-dyq/Go.js-Lite/releases). Extract, then **upload the `gojs/` directory** as a whole to your web root.

- Panel URL: `https://your-domain.com/gojs/`
- All panel files are isolated inside `gojs/` — they will never interfere with your existing site ✅

### For Developers (Local Development)

```bash
# Clone the repo
git clone https://github.com/YQteam-dyq/Go.js-Lite.git
cd Go.js-Lite

# Install dependencies
npm install

# Start PHP backend (port 8080) with /gojs/ prefix-aware router
php -S 127.0.0.1:8080 router.php

# Start frontend dev server (port 5173), auto-proxies /gojs/api
npm run dev
```

Visit http://localhost:5173/gojs/ to start developing.

<details><summary>📖 中文翻译</summary>

## 🚀 快速开始

### 普通用户（部署使用）

从 [Releases](https://github.com/YQteam-dyq/Go.js-Lite/releases) 下载最新的 `gojs-lite-VERSION.zip`，解压后把 **`gojs/` 目录**整个上传到你的 Web 根目录即可。

- 面板入口：`https://你的域名/gojs/`
- 面板文件全部独立于根目录，不影响你原有站点内容 ✅

### 开发者（本地开发）

```bash
# 克隆项目
git clone https://github.com/YQteam-dyq/Go.js-Lite.git
cd Go.js-Lite

# 安装依赖
npm install

# 启动 PHP 后端（端口 8080），路由支持 /gojs/ 前缀
php -S 127.0.0.1:8080 router.php

# 启动前端开发服务器（端口 5173），已自动代理 /gojs/api
npm run dev
```

访问 http://localhost:5173/gojs/ 即可开发。

</details>

---

## ✨ Features

- 📦 **Decoupled deployment** — All panel files ship inside the standalone `gojs/` subdirectory. Your web root stays clean.
- 🔑 **Secret access URL** — Access the panel via a token-based URL to hide its existence from public discovery.
- 🎯 **Shared hosting friendly** — Automatically detects `disable_functions`, gracefully degrades based on available capabilities.
- 📱 **Mobile-first** — Responsive design, perfect on phones, tablets, and desktops. Touch-friendly.
- 🔒 **Secure & reliable** — BCrypt password hashing, CSRF protection, path traversal prevention, system file protection.
- 📁 **File management** — Browse / edit / upload / download files online, permission changes supported.
- 🗜️ **Zip / Tar archives** — Compress & extract zip / tar.gz archives online ✅
- 🗄️ **Database management** — MySQL connections, SQL console, table structure browser, **.sql import & export** ✅
- 🐛 **PHP error log viewer** — Auto-detects log paths, categorised filtering, live refresh ✅
- 🧱 **Health check** — One-click PHP security / performance / compatibility audit.
- 🧮 **Disk analysis** — Visualises per-directory usage and identifies large files.
- ℹ️ **System info** — PHP info, server environment, disk usage, **memory monitor**, process CPU ✅
- 🌐 **Bilingual (EN/ZH)** — Built-in i18n, supports both Chinese and English.
- 🌙 **Light / dark themes** — Supports light / dark / system preference.
- ⚡ **Modern frontend** — React + TypeScript + Vite + Tailwind CSS.

<details><summary>📖 中文翻译</summary>

## ✨ 特性

- 📦 **轻量解耦部署** — 面板文件打包进独立 `gojs/` 子目录，不与用户站点抢占 Web 根目录
- 🔑 **私密入口** — 带 token 的访问链接，隐藏面板存在，提高安全性
- 🎯 **共享主机友好** — 自动探测 `disable_functions`，功能按能力优雅降级
- 📱 **移动端优先** — 响应式设计，手机 / 平板 / 桌面完美适配，触控友好
- 🔒 **安全可靠** — BCrypt 密码、CSRF 防护、路径越权防护、系统文件保护
- 📁 **文件管理** — 在线浏览 / 编辑 / 上传 / 下载，支持权限修改
- 🗜️ **Zip / Tar 压缩解压** — 在线一键压缩、解压 zip / tar.gz 文件 ✅
- 🗄️ **数据库管理** — MySQL 连接管理、SQL 控制台、表结构浏览、**.sql 导入导出** ✅
- 🐛 **PHP 错误日志** — 自动探测错误日志位置，按类型分类、实时查看 ✅
- 🧱 **配置体检** — PHP 安全 / 性能 / 兼容性一键检查
- 🧮 **磁盘分析** — 可视化展示目录占用与大文件
- ℹ️ **系统信息** — PHP 信息、服务器环境、磁盘使用、**内存使用监控**、进程 CPU ✅
- 🌐 **中英文双语** — 内置 i18n，支持中文和英文
- 🌙 **明暗主题** — 支持浅色 / 深色 / 跟随系统
- ⚡ **现代前端** — React + TypeScript + Vite + Tailwind CSS

</details>

---

## 🚀 Quick Start

### Requirements

| Item | Minimum | Recommended |
|------|---------|-------------|
| PHP | 7.4 | 8.0+ |
| Web Server | Apache / Nginx / LiteSpeed | Apache + mod_rewrite |
| PHP Extensions | `session`, `json`, `mbstring` | `mysqli`, `gd`, `openssl`, `zip` |
| Browser | Chrome 80+ / Safari 14+ | Latest stable |

### Deployment

1. **Download** the latest release (`gojs-lite-VERSION.zip`)
2. **Extract** the archive — you get a single standalone `gojs/` folder
3. **Upload** the `gojs/` folder to your web root (e.g. `public_html/gojs/`, `wwwroot/gojs/`)
4. **Visit** `https://your-domain.com/gojs/` — the setup wizard starts automatically
5. **Set** an admin password, save your secret access URL, and you're done ✅

> 💡 **Note**: All panel assets live inside `gojs/`. Zero pollution to the rest of your site.

### Directory Structure

After deployment on the server:

```
public_html/              ← Your user site (panel never touches it)
├── index.html / index.php ← Keep your original content as-is
└── gojs/                  ← Panel lives here, access through this path
    ├── api.php            # Backend API (single file)
    ├── .htaccess          # Apache rewrite rules (RewriteBase /gojs/)
    └── dist/              # Frontend build
        ├── index.html
        └── assets/
```

Config files are automatically created in the panel's parent directory:

```
public_html/
└── .gojs/
    ├── config.php         # Main config (PHP array, web access blocked)
    └── auth.log           # Login log (brute-force protection)
```

<details><summary>📖 中文翻译</summary>

## 🚀 快速开始

### 环境要求

| 项目 | 最低要求 | 推荐 |
|------|---------|------|
| PHP | 7.4 | 8.0+ |
| Web 服务器 | Apache / Nginx / LiteSpeed | Apache + mod_rewrite |
| PHP 扩展 | `session`、`json`、`mbstring` | `mysqli`、`gd`、`openssl`、`zip` |
| 浏览器 | Chrome 80+ / Safari 14+ | 最新版 |

### 部署步骤

1. **下载** 最新发布包（`gojs-lite-VERSION.zip`）
2. **解压** 得到独立的 `gojs/` 目录
3. **上传** `gojs/` 目录到 Web 根目录（`public_html/gojs/`、`wwwroot/gojs/` 等）
4. **访问** `https://你的域名/gojs/`，自动进入安装向导
5. **设置** 管理员密码，保存私密访问链接，完成安装 ✅

> 💡 **注意**：面板文件全部集中在 `gojs/` 子目录内，对根目录现有站点零侵入。

### 目录结构

部署到服务器后的结构：

```
public_html/              ← 你的用户站点（面板不抢占根目录）
├── index.html / index.php ← 用户自己的网站内容，保持原样
└── gojs/                  ← 面板独立子目录（从此链接进入）
    ├── api.php            # 后端 API（单文件）
    ├── .htaccess          # Apache 重写规则（RewriteBase /gojs/）
    └── dist/              # 前端构建产物
        ├── index.html
        └── assets/
```

配置文件会在安装时自动生成在面板父目录：

```
public_html/
└── .gojs/
    ├── config.php         # 主配置（PHP 数组，禁止 Web 访问）
    └── auth.log           # 登录日志（暴力破解防护）
```

</details>

---

## ⚡ Performance Tuning

### OPcache

Every request to the panel goes through `api.php`. Enabling **OPcache** lets PHP cache the compiled bytecode so scripts no longer need to be re-parsed on every request, which significantly reduces the per-request cost of `api.php`.

Recommended `php.ini` settings:

```ini
opcache.enable = 1                    ; enable the opcode cache (on by default in production)
opcache.enable_cli = 1                ; optional: also enable for CLI (e.g. cron) scenarios
opcache.validate_timestamps = 1       ; re-check file mtimes to detect code changes
opcache.revalidate_freq = 60          ; check for changed files at most once per 60s
opcache.memory_consumption = 128      ; 128 MB of shared memory for cached opcodes
opcache.max_accelerated_files = 10000 ; enough slots for the codebase
```

> 💡 **Tip**: In production, after deploying a new release you can either clear the cache (e.g. `opcache_reset()` / restart PHP-FPM) or briefly set `opcache.validate_timestamps = 0` while keeping `opcache.revalidate_freq` for development. If OPcache is not available, the panel still works correctly — it just parses files on every request.

### On-demand Loading

The backend logic has been split from a single monolithic `api.php` into modules under `backend/` (auth, files, database, ssl, backup, system, settings, cron, notifications, misc, …). A lightweight `autoload.php` loads only the modules needed for the current request, instead of parsing the whole file every time. This keeps the per-request parse footprint small and makes the codebase easier to maintain.

<details><summary>📖 中文翻译</summary>

## ⚡ 性能优化建议

### OPcache

面板的每次请求都会经过 `api.php`。开启 **OPcache** 后，PHP 会把编译后的字节码缓存起来，脚本无需在每次请求时重新解析，可显著降低每请求解析 `api.php` 的开销。

推荐的 `php.ini` 配置：

```ini
opcache.enable = 1                    ; 开启操作码缓存（生产环境默认开启）
opcache.enable_cli = 1                ; 可选：CLI（如 cron）场景也启用
opcache.validate_timestamps = 1       ; 检查文件修改时间，感知代码变更
opcache.revalidate_freq = 60          ; 每 60 秒最多检查一次文件是否变更
opcache.memory_consumption = 128      ; 128 MB 共享内存用于缓存操作码
opcache.max_accelerated_files = 10000 ; 足够的缓存槽位
```

> 💡 **提示**：生产环境发布新版本后，可通过清除缓存（如 `opcache_reset()` / 重启 PHP-FPM），或临时将 `opcache.validate_timestamps = 0` 来使新代码生效；开发环境保留 `opcache.revalidate_freq` 即可。若主机未提供 OPcache，面板仍能正常工作——只是每次请求都会重新解析文件。

### 按需加载

后端逻辑已从单一的大型 `api.php` 拆分到 `backend/` 下的各模块（auth、files、database、ssl、backup、system、settings、cron、notifications、misc 等）。轻量 `autoload.php` 只加载当前请求所需的模块，而非每次都解析整个文件，从而减小单请求的解析体积，也让代码更易维护。

</details>

---

## 📱 Feature Overview

### Core Features

| Feature | Description | Status |
|---------|-------------|--------|
| 🔐 Auth System | Setup wizard, login/logout, change password, session timeout | ✅ |
| 🔑 Secret Access | Token-based access URL, hides panel existence | ✅ |
| 📊 Dashboard | System overview, disk usage, file stats, recently modified files | ✅ |
| 📁 File Manager | Directory browser, file editor, upload/download, create/delete/rename, permissions | ✅ |
| 🗜️ Zip / Tar | Compress to zip/tar.gz, extract any archive | ✅ |
| 🗄️ Database Mgmt | MySQL connections, database/table/column browser, SQL console | ✅ |
| 📤 SQL Import/Export | One-click full/single-table export, chunked .sql import | ✅ |
| 🐛 PHP Error Log | Auto-detects log path, categorised filtering, live refresh | ✅ |
| 🧱 Health Check | One-click PHP security / performance / compatibility audit | ✅ |
| 🧮 Disk Analysis | Per-directory size visualisation, large files list | ✅ |
| ℹ️ PHP Info | Version, extensions, ini directives, one-click copy php.ini path | ✅ |
| 💻 System Info | Disk, load, uptime, memory usage, process CPU, Cron | ✅ |
| ⚙️ Settings | Theme / language switch, session settings, password change, access URL i18n | ✅ |

### Capability-based Degradation

Go.js Lite automatically detects your server environment and hides unavailable features:

| Feature | Dependency | When unavailable |
|---------|------------|-----------------|
| Database management | `mysqli` or `pdo_mysql` extension | Database menu hidden |
| Zip compression | `ZipArchive` class | Compress button hidden |
| Process list | `/proc` readable | Processes tab hidden |
| Cron management | `exec()` function | Cron menu hidden |
| Image thumbnails | `gd` extension | No thumbnails shown |

<details><summary>📖 中文翻译</summary>

## 📱 功能预览

### 核心功能

| 功能 | 说明 | 状态 |
|------|------|------|
| 🔐 认证系统 | 安装引导、登录/登出、修改密码、会话超时、暴力破解防护 | ✅ |
| 📊 仪表盘 | 系统概览、磁盘使用、文件统计、最近修改文件 | ✅ |
| 📁 文件管理 | 目录浏览、文件编辑、上传/下载、创建/删除/重命名、权限修改 | ✅ |
| 🗜️ Zip / Tar 压缩解压 | 文件/目录压缩为 zip / tar.gz，解压任意归档 | ✅ |
| 🗄️ 数据库管理 | MySQL 连接管理、数据库/表/列浏览、SQL 控制台 | ✅ |
| 📤 SQL 导入导出 | 一键导出整库或单表、分块导入 .sql 文件 | ✅ |
| 🐛 PHP 错误日志 | 自动探测日志路径、按错误类型分类过滤、实时刷新 | ✅ |
| 🧱 配置体检 | PHP 安全 / 性能 / 兼容性一键检查与建议 | ✅ |
| 🧮 磁盘分析 | 目录大小占比可视化、大文件列表 | ✅ |
| ℹ️ PHP 信息 | 版本、扩展、ini 配置、复制 php.ini 路径 | ✅ |
| 💻 系统信息 | 磁盘、负载、运行时间、内存使用、进程 CPU、Cron | ✅ |
| ⚙️ 设置 | 主题切换、语言切换、会话设置、密码修改、私密链接 i18n | ✅ |

### 能力降级

Go.js 会自动探测服务器环境，不可用的功能自动隐藏：

| 功能 | 依赖 | 不可用时 |
|------|------|---------|
| 数据库管理 | `mysqli` 或 `pdo_mysql` 扩展 | 隐藏数据库菜单 |
| Zip 压缩解压 | `ZipArchive` 类 | 隐藏压缩按钮 |
| 进程列表 | `/proc` 可读 | 隐藏进程标签 |
| Cron 管理 | `exec()` 函数 | 隐藏 Cron 菜单 |
| 图片预览 | `gd` 扩展 | 不显示缩略图 |

</details>

---

## 🔒 Security

- Admin password hashed with `password_hash(PASSWORD_BCRYPT)` — one-way, irreversible.
- Database connection passwords encrypted with `AES-256-CBC`.
- All file operations are anchored to a strict `$files_root` realpath — no path traversal.
- System files (`.gojs/`, `api.php`, `.htaccess`) are protected from file manager operations.
- Config directory `.gojs/` blocked from direct web access via `.htaccess`.
- CSRF token validation — cross-site request forgery protection.
- Session / Cookie scope shrunk to `/gojs/` — never leaks to sibling apps in the web root.
- 🔑 **Secret access URL** — Panel requires a token in the URL, hiding its existence.
- 🛡️ **Subdirectory isolation** — Panel owns the `/gojs/` path and nothing else.

<details><summary>📖 中文翻译</summary>

## 🔒 安全说明

- 主密码使用 `password_hash(PASSWORD_BCRYPT)` 哈希存储，不可逆
- 数据库连接密码使用 `AES-256-CBC` 加密存储
- 所有文件操作使用绝对路径锚定 `$files_root`，严格防止路径越权
- 系统文件（`.gojs/`、`api.php`、`.htaccess`）受保护，禁止通过文件管理器操作
- 配置目录 `.gojs/` 通过 `.htaccess` 禁止 Web 直接访问
- CSRF Token 校验，防跨站请求伪造
- Session / Cookie 作用域收缩至 `/gojs/` 路径，不会影响根目录下其他应用
- 🔑 **私密入口** — 面板访问需要带 token 的 URL，隐藏面板存在
- 🛡️ **子目录隔离** — 面板独占 `/gojs/` 子目录，不会污染用户根目录路由

</details>

---

## 📄 License

[MIT License](LICENSE)

<details><summary>📖 中文翻译</summary>

## 📄 开源协议

[MIT License](LICENSE)

</details>

---

## 👥 Developers

**YQteam-dyq** — Crafted with care, lightweight & efficient.

<details><summary>📖 中文翻译</summary>

## 👥 开发者

**YQteam-dyq** — 用心打造，轻量高效

</details>

---

## 🙏 Acknowledgments

- [React](https://react.dev)
- [Vite](https://vitejs.dev)
- [Tailwind CSS](https://tailwindcss.com)
- [Lucide Icons](https://lucide.dev)
- [TanStack Query](https://tanstack.com/query)
- [Zustand](https://github.com/pmndrs/zustand)

<details><summary>📖 中文翻译</summary>

## 🙏 致谢

- [React](https://react.dev)
- [Vite](https://vitejs.dev)
- [Tailwind CSS](https://tailwindcss.com)
- [Lucide Icons](https://lucide.dev)
- [TanStack Query](https://tanstack.com/query)
- [Zustand](https://github.com/pmndrs/zustand)

</details>

---

<p align="center">
  Made with ❤️ by YQteam-dyq
</p>
