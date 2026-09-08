# Go.js Lite — 轻量级 PHP 共享主机管理面板

> 专为 PHP 共享主机打造的轻量级服务器管理面板，**不抢占 Web 根目录**，移动端友好。

[English (main README)](README.md) · **中文全文本**

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)](https://php.net)
[![React](https://img.shields.io/badge/React-18-61dafb.svg)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178c6.svg)](https://www.typescriptlang.org)
[![Version](https://img.shields.io/badge/version-0.7.0-blue.svg)](CHANGELOG.md)

---

## 0.7.0 有什么新东西

- **统一 REST 契约** — 保持**查询式** `/gojs/api?api=<action>` 作为主路由形态；同时**新增路径式** `/gojs/api/<action>` 作为兼容入口，`router.php` 与 `.htaccess` 双路识别。查询式是面板一直以来的默认形态，不会被弃用。
- **文件管理** — 每次保存生成历史快照（可在 `.gojs/file-history/` 找回）；内置图片 / 视频 / 音频 / Markdown / PDF / CSV 浏览器预览；批量操作（多选删除 / 复制 / 移动 / 打包 / chmod）；可选 AES-256-GCM 单文件加密，密钥由管理员口令经 HKDF 派生。
- **数据库管理** — 持久化连接、慢查询日志（`.gojs/slow_queries.log`）、schema 快照（`.gojs/db_snapshots/`）、导出时敏感列脱敏。
- **资源监控** — CPU / 内存 / 磁盘趋势图（5m / 1h / 24h 窗口，存于 `monitor_history.json`），每个 API 调用量 / 延迟 / 错误率写入 `.gojs/api_metrics.json`（滚动 7 天）。
- **通知中心** — 在原有邮件 / 钉钉 / 飞书 / Telegram 之外，新增 Microsoft Teams 与 Slack Incoming Webhook 适配器。
- **安全加固** — 暴力破解封禁升级为「IP + UA + 国家」三重判定；新增 per-user / per-endpoint 限流，默认 60 req/min，文件操作 20 req/min，超额返回 429 并带 `retry_after`。
- **运维追溯** — 所有写操作日志都带上 `request_id` / `trace_id`；30 天未活动会话自动清理，`auth.log` 归档。
- **诊断导出** — `/.gojs/diagnostics/export` 一键打包脱敏后的运行时快照，方便排障。

0.7.0 继续保持 Go.js Lite 的轻量级定位——单文件 PHP 入口（`api.php` + `router.php`），模块化的 `backend/`，内置 `webcron.php`，不引入任何外部服务。完整变更与升级说明见 [CHANGELOG.md](CHANGELOG.md)。

---

## 快速开始

### 普通用户（部署使用）

从 [Releases](https://github.com/YQteam-dyq/Go.js-Lite/releases) 下载最新的 `gojs-lite-VERSION.zip`，解压后把 **`gojs/` 目录**整个上传到你的 Web 根目录即可（目录名可任意，如 `gojs`、`panel`，或直接上传到根目录）。

- 面板入口：`https://你的域名/<部署目录>/`（例如部署到 `gojs` 目录则访问 `https://你的域名/gojs/`，首次访问自动进入安装向导）
- 面板文件全部独立于 Web 根目录，不影响你原有站点内容

> **部署前置要求**：主机需开启 **mod_rewrite** 且允许目录使用 `.htaccess`（多数虚拟主机默认开启）。`.htaccess` 使用相对路径重写，**部署在任意子目录都无需修改**；若遇到 403，请检查主机是否禁止了 `.htaccess` 生效。

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

---

## 特性

- **[部署]** 轻量解耦部署 — 面板文件打包进独立 `gojs/` 子目录，不与用户站点抢占 Web 根目录
- **[入口]** 私密入口 — 带 token 的访问链接，隐藏面板存在，提高安全性
- **[兼容]** 共享主机友好 — 自动探测 `disable_functions`，功能按能力优雅降级
- **[UI]** 移动端优先 — 响应式设计，手机 / 平板 / 桌面完美适配，触控友好
- **[安全]** 安全可靠 — BCrypt 密码、CSRF 防护、路径越权防护、系统文件保护
- **[文件]** 文件管理 — 在线浏览 / 编辑 / 上传 / 下载，支持权限修改
- **[压缩]** Zip / Tar 压缩解压 — 在线一键压缩、解压 zip / tar.gz 文件
- **[DB]** 数据库管理 — MySQL 连接管理、SQL 控制台、表结构浏览、**.sql 导入导出**
- **[日志]** PHP 错误日志 — 自动探测错误日志位置，按类型分类、实时查看
- **[检查]** 配置体检 — PHP 安全 / 性能 / 兼容性一键检查
- **[磁盘]** 磁盘分析 — 可视化展示目录占用与大文件
- **[信息]** 系统信息 — PHP 信息、服务器环境、磁盘使用、内存使用监控、进程 CPU
- **[趋势]** 资源趋势 — 仪表盘内 CPU / 内存 / 磁盘趋势图
- **[防护]** 三重防护 — 暴力破解封禁升级为 IP + UA + 国家三重判定
- **[追溯]** 操作追溯 — 所有写操作日志都带 `request_id` / `trace_id`
- **[双语]** 中英文双语 — 内置 i18n，支持中文和英文
- **[主题]** 明暗主题 — 支持浅色 / 深色 / 跟随系统
- **[栈]** 现代前端 — React + TypeScript + Vite + Tailwind CSS

---

## 环境要求与部署

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
3. **上传** `gojs/` 目录到 Web 根目录（`public_html/gojs/`、`wwwroot/gojs/` 等，目录名可任意）
4. **访问** `https://你的域名/gojs/`，自动进入安装向导
5. **设置** 管理员密码，保存私密访问链接，完成安装

> **注意**：面板文件全部集中在 `gojs/` 子目录内，对根目录现有站点零侵入。

### 目录结构

部署到服务器后的结构：

```
public_html/              ← 你的用户站点（面板不抢占根目录）
├── index.html / index.php ← 用户自己的网站内容，保持原样
└── gojs/                  ← 面板独立子目录（从此链接进入）
    ├── api.php            # 后端 API（单文件）
    ├── router.php         # PHP 内置服务器路由（php -S 场景）
    ├── .htaccess          # Apache 重写规则（相对路径，自适应任意挂载点）
    ├── dist/              # 前端构建产物
    │   ├── index.html
    │   └── assets/
    └── .gojs/             # 运行时配置（安装时自动生成，禁止 Web 访问）
        ├── config.php     # 主配置（PHP 数组）
        └── auth.log       # 登录日志（暴力破解防护）
```

> **挂载点不是 `/gojs/` 时**（例如部署在 `panel/` 或根目录）：后端 `.htaccess` 与 `router.php` 均自动适配，无需改动；但前端构建产物内的资源路径按 `vite base` 写入。若前端 404，请在项目根目录用实际路径重新构建：
>
> ```bash
> npx vite build --base=/panel/    # 部署在 /panel/ 时；根目录部署用 --base=/
> ```
>
> 然后重新上传 `dist/` 目录。

---

## 性能优化建议

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

> **提示**：生产环境发布新版本后，可通过清除缓存（如 `opcache_reset()` / 重启 PHP-FPM），或临时将 `opcache.validate_timestamps = 0` 来使新代码生效；开发环境保留 `opcache.revalidate_freq` 即可。若主机未提供 OPcache，面板仍能正常工作——只是每次请求都会重新解析文件。

### 按需加载

后端逻辑已从单一的大型 `api.php` 拆分到 `backend/` 下的各模块（auth、files、database、ssl、backup、system、settings、cron、notifications、misc 等）。轻量 `autoload.php` 只加载当前请求所需的模块，而非每次都解析整个文件，从而减小单请求的解析体积，也让代码更易维护。

### API 路由

面板支持两种 API 调用形态，**任选其一**即可：

| 形态 | 示例 | 说明 |
|------|------|------|
| 查询式（**默认**） | `/gojs/api?api=login` | 历史默认形态，所有调用都走这一条 |
| 路径式（兼容） | `/gojs/api/login` | 等价别名，`router.php` 同样识别并派发到同一个 handler |

两种形态都由 `router.php` 统一派发到 `api.php`，不存在两条不同代码路径。

---

## 功能预览

### 核心功能

| 功能 | 说明 | 状态 |
|------|------|------|
| 认证系统 | 安装引导、登录/登出、修改密码、会话超时、暴力破解防护 | 已支持 |
| 私密入口 | 带 token 的访问链接，隐藏面板存在 | 已支持 |
| 仪表盘 | 系统概览、磁盘使用、文件统计、最近修改文件 | 已支持 |
| 文件管理 | 目录浏览、文件编辑、上传/下载、创建/删除/重命名、权限修改、历史快照、内置预览、批量操作 | 已支持 |
| Zip / Tar 压缩解压 | 文件/目录压缩为 zip / tar.gz，解压任意归档 | 已支持 |
| 数据库管理 | MySQL 连接管理、数据库/表/列浏览、SQL 控制台 | 已支持 |
| SQL 导入导出 | 一键导出整库或单表、分块导入 .sql 文件 | 已支持 |
| PHP 错误日志 | 自动探测日志路径、按错误类型分类过滤、实时刷新 | 已支持 |
| 配置体检 | PHP 安全 / 性能 / 兼容性一键检查与建议 | 已支持 |
| 磁盘分析 | 目录大小占比可视化、大文件列表 | 已支持 |
| PHP 信息 | 版本、扩展、ini 配置、复制 php.ini 路径 | 已支持 |
| 系统信息 | 磁盘、负载、运行时间、内存使用、进程 CPU、Cron | 已支持 |
| 资源趋势 | CPU / 内存 / 磁盘趋势图 | 已支持 |
| 通知中心 | 邮件 / 钉钉 / 飞书 / Telegram / Microsoft Teams / Slack Incoming Webhook | 已支持 |
| 操作日志 | 所有写操作日志都带 `request_id` / `trace_id` | 已支持 |
| 设置 | 主题切换、语言切换、会话设置、密码修改、私密链接 i18n | 已支持 |

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

## 安全说明

- 主密码使用 `password_hash(PASSWORD_BCRYPT)` 哈希存储，不可逆
- 数据库连接密码使用 `AES-256-CBC` 加密存储
- 文件管理器支持可选的 `AES-256-GCM` 单文件加密（每文件独立密钥，由管理员口令派生）
- 所有文件操作使用绝对路径锚定 `$files_root`，严格防止路径越权
- 系统文件（`.gojs/`、`api.php`、`.htaccess`）受保护，禁止通过文件管理器操作
- 配置目录 `.gojs/` 通过 `.htaccess` 禁止 Web 直接访问
- CSRF Token 校验，防跨站请求伪造；服务端限流强制写操作必须带 `X-CSRF-Token`
- Session / Cookie 作用域收缩至 `/gojs/` 路径，不会影响根目录下其他应用
- **[入口]** 私密入口 — 面板访问需要带 token 的 URL，隐藏面板存在
- **[隔离]** 子目录隔离 — 面板独占 `/gojs/` 子目录，不会污染用户根目录路由
- **[防护]** 暴力破解三重防护 — IP + UA + 国家三重判定（阈值可配置）

---

## 开源协议

[Apache License 2.0](LICENSE)

本项目同时附带 `NOTICE` 文件（Apache License 2.0 要求）。

---

## 开发者

**YQteam-dyq** — 用心打造，轻量高效

---

## 致谢

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
