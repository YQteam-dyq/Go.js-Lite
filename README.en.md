# Go.js — Lightweight PHP Shared Hosting Control Panel

> A lightweight server management panel built specifically for PHP shared hosting. Single-file deployment, mobile-friendly.

English | [中文](README.md)

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.0-777bb4.svg)](https://php.net)
[![React](https://img.shields.io/badge/React-18-61dafb.svg)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178c6.svg)](https://www.typescriptlang.org)

---

## 🚀 Quick Start

### For Users (Deployment)

Download the latest `gojs-VERSION.zip` from [Releases](https://github.com/YQteam/gojs/releases), extract and upload to your web root.

### For Developers (Local Development)

```bash
# Clone the repo
git clone https://github.com/YQteam/gojs.git
cd gojs

# Install dependencies
npm install

# Start PHP backend (port 8080)
php -S 127.0.0.1:8080 -t .

# Start frontend dev server (port 5173)
npm run dev
```

Visit http://localhost:5173 to start developing.

## ✨ Features

- 📦 **Single-file deployment** — Upload `index.php` + `dist/` to your web root and you're good to go. No composer, no Node.js required.
- 🎯 **Shared hosting friendly** — Automatically detects `disable_functions`, gracefully degrades based on available capabilities.
- 📱 **Mobile-first** — Responsive design, perfect on phones, tablets, and desktops. Touch-friendly.
- 🔒 **Secure & reliable** — BCrypt password hashing, CSRF protection, path traversal prevention, brute-force protection.
- 📁 **File management** — Browse / edit / upload / download files online, permission changes supported.
- 🗄️ **Database management** — MySQL connection management, SQL console, table structure browser.
- ℹ️ **System information** — PHP info, server environment, disk usage, process list (optional).
- 🌐 **Bilingual (EN/ZH)** — Built-in i18n, auto-detects browser language.
- 🌙 **Light / dark themes** — Supports light / dark / system preference.
- ⚡ **Modern frontend** — React + TypeScript + Vite + Tailwind CSS.

---

## 🚀 Quick Start

### Requirements

| Item | Minimum | Recommended |
|------|---------|-------------|
| PHP | 7.0 | 7.4+ |
| Web Server | Apache / Nginx / LiteSpeed | Apache + mod_rewrite |
| PHP Extensions | `session`, `json`, `mbstring` | `mysqli`, `gd`, `openssl`, `zip` |
| Browser | Chrome 80+ / Safari 14+ | Latest stable |

### Deployment

1. **Download** the latest release (`gojs-VERSION.zip`)
2. **Upload** all files to your web root (e.g. `public_html/`, `wwwroot/`)
3. **Visit** your domain — the setup wizard will start automatically
4. **Set** an admin password and you're done ✅

### Directory Structure

After deployment on the server:

```
public_html/
├── index.php          # Backend API (single file)
├── .htaccess          # Apache rewrite rules
└── dist/              # Frontend build
    ├── index.html
    └── assets/
```

Config files are automatically created during setup:

```
.gojs/
├── config.php         # Main config (PHP array, web access blocked)
└── auth.log           # Login log (brute-force protection)
```

---

## 📱 Feature Overview

### Core Features

| Feature | Description | Status |
|---------|-------------|--------|
| 🔐 Auth System | Setup wizard, login/logout, change password, session timeout, brute-force protection | ✅ |
| 📊 Dashboard | System overview, disk usage, file stats, recently modified files | ✅ |
| 📁 File Manager | Directory browser, file editor, upload/download, create/delete/rename, permissions | ✅ |
| 🗄️ Database Mgmt | MySQL connections, database/table/column browser, SQL console | ✅ |
| ℹ️ PHP Info | Version, extensions, ini config, server variables | ✅ |
| 💻 System Info | Disk, load, uptime, process list, cron | ✅ |
| ⚙️ Settings | Theme switch, language switch, session settings, password change, developer info | ✅ |
| 📦 Zip Archives | Compress / extract files & directories | 🚧 Coming soon |
| 📤 SQL Import/Export | .sql file import & export | 🚧 Coming soon |

### Capability-based Degradation

Go.js automatically detects your server environment and hides unavailable features:

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
- All file operations validated via `realpath()` to prevent path traversal.
- Login locked for 15 minutes after 5 failed attempts — brute-force protection.
- Config directory `.gojs/` blocked from direct web access via `.htaccess`.
- CSRF token validation — cross-site request forgery protection.

---

## 📄 License

[MIT License](LICENSE)

---

## 👥 Developers

**YQteam** — Crafted with care, lightweight & efficient.

- Team website: [yq-tuandui.xyz](https://yq-tuandui.xyz)
- Project website: [gojs.yq-tuandui.xyz](https://gojs.yq-tuandui.xyz)

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
  Made with ❤️ by YQteam
</p>
