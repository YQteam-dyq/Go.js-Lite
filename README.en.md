# Go.js Lite — Lightweight PHP Shared Hosting Control Panel

> A lightweight server management panel built specifically for PHP shared hosting. **Does not occupy your web root**. Mobile-friendly.

English | [中文](README.md)

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

---

## 📄 License

[MIT License](LICENSE)

---

## 👥 Developers

**YQteam-dyq** — Crafted with care, lightweight & efficient.

---

## 🙏 Acknowledgments

- [React](https://react.dev)
- [Vite](https://vitejs.dev)
- [Tailwind CSS](https://tailwindcss.com)
- [Lucide Icons](https://lucide.dev)
- [TanStack Query](https://tanstack.com/query)
- [Zustand](https://github.com/pmndrs/zustand)

---

<p align="center">
  Made with ❤️ by YQteam-dyq
</p>
