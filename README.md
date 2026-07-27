# Go.js Lite — 轻量级 PHP 共享主机管理面板

> 专为 PHP 共享主机打造的轻量级服务器管理面板，移动端友好。

[English](README.en.md) | 中文

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)](https://php.net)
[![React](https://img.shields.io/badge/React-18-61dafb.svg)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178c6.svg)](https://www.typescriptlang.org)

---

## 🚀 快速开始

### 普通用户（部署使用）

从 [Releases](https://github.com/YQteam-dyq/Go.js-Lite/releases) 下载最新的 `gojs-lite-VERSION.zip`，解压后上传到 Web 根目录即可使用。

### 开发者（本地开发）

```bash
# 克隆项目
git clone https://github.com/YQteam-dyq/Go.js-Lite.git
cd Go.js-Lite

# 安装依赖
npm install

# 启动 PHP 后端（端口 8080）
php -S 127.0.0.1:8080 -t .

# 启动前端开发服务器（端口 5173）
npm run dev
```

访问 http://localhost:5173 即可开发。

## ✨ 特性

- 📦 **轻量部署** — 上传 `index.php` + `dist/` 到 Web 根目录即可使用，无需 composer、无需 Node
- 🔑 **私密入口** — 带 token 的访问链接，隐藏面板存在，提高安全性
- 🎯 **共享主机友好** — 自动探测 `disable_functions`，功能按能力优雅降级
- 📱 **移动端优先** — 响应式设计，手机 / 平板 / 桌面完美适配，触控友好
- 🔒 **安全可靠** — BCrypt 密码、CSRF 防护、路径越权防护、系统文件保护
- 📁 **文件管理** — 在线浏览 / 编辑 / 上传 / 下载，支持权限修改
- 🗄️ **数据库管理** — MySQL 连接管理、SQL 控制台、表结构浏览
- ℹ️ **系统信息** — PHP 信息、服务器环境、磁盘使用
- 🌐 **中英文双语** — 内置 i18n，支持中文和英文
- 🌙 **明暗主题** — 支持浅色 / 深色 / 跟随系统
- ⚡ **现代前端** — React + TypeScript + Vite + Tailwind CSS

---

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
2. **上传** 解压后的所有文件到 Web 根目录（如 `public_html/`、`wwwroot/`）
3. **访问** 你的域名，自动进入安装向导
4. **设置** 管理员密码，保存私密访问链接，完成安装 ✅

### 目录结构

部署到服务器后的结构：

```
public_html/
├── index.php          # 后端 API（单文件）
├── .htaccess          # Apache 重写规则
└── dist/              # 前端构建产物
    ├── index.html
    └── assets/
```

配置文件会在安装时自动生成：

```
.gojs/
├── config.php         # 主配置（PHP 数组，禁止 Web 访问）
└── auth.log           # 登录日志（暴力破解防护）
```

---

## 📱 功能预览

### 核心功能

| 功能 | 说明 | 状态 |
|------|------|------|
| 🔐 认证系统 | 安装引导、登录/登出、修改密码、会话超时、暴力破解防护 | ✅ |
| 📊 仪表盘 | 系统概览、磁盘使用、文件统计、最近修改文件 | ✅ |
| 📁 文件管理 | 目录浏览、文件编辑、上传/下载、创建/删除/重命名、权限修改 | ✅ |
| 🗄️ 数据库管理 | MySQL 连接管理、数据库/表/列浏览、SQL 控制台 | ✅ |
| ℹ️ PHP 信息 | 版本、扩展、ini 配置、服务器变量 | ✅ |
| 💻 系统信息 | 磁盘、负载、运行时间、进程列表、Cron | ✅ |
| ⚙️ 设置 | 主题切换、语言切换、会话设置、密码修改、开发者信息 | ✅ |
| 📦 Zip 压缩解压 | 文件/目录压缩、解压 | 🚧 后续版本 |
| 📤 SQL 导入导出 | .sql 文件导入导出 | 🚧 后续版本 |

### 能力降级

Go.js 会自动探测服务器环境，不可用的功能自动隐藏：

| 功能 | 依赖 | 不可用时 |
|------|------|---------|
| 数据库管理 | `mysqli` 或 `pdo_mysql` 扩展 | 隐藏数据库菜单 |
| Zip 压缩解压 | `ZipArchive` 类 | 隐藏压缩按钮 |
| 进程列表 | `/proc` 可读 | 隐藏进程标签 |
| Cron 管理 | `exec()` 函数 | 隐藏 Cron 菜单 |
| 图片预览 | `gd` 扩展 | 不显示缩略图 |

---

## 🔒 安全说明

- 主密码使用 `password_hash(PASSWORD_BCRYPT)` 哈希存储，不可逆
- 数据库连接密码使用 `AES-256-CBC` 加密存储
- 所有文件操作经过 `realpath()` 校验，防止路径遍历攻击
- 系统文件（`.gojs/`、`index.php`）受保护，禁止通过文件管理器操作
- 配置目录 `.gojs/` 通过 `.htaccess` 禁止 Web 直接访问
- CSRF Token 校验，防跨站请求伪造
- 🔑 **私密入口** — 面板访问需要带 token 的 URL，隐藏面板存在

---

## 📄 开源协议

[MIT License](LICENSE)

---

## 👥 开发者

**YQteam-dyq** — 用心打造，轻量高效

---

## 🙏 致谢

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
