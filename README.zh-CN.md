# Go.js Lite — 轻量级 PHP 共享主机管理面板

> 专为 PHP 共享主机打造的轻量级服务器管理面板，**不抢占 Web 根目录**，移动端友好。

[English (main README)](README.md) · **中文全文本**

[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)](https://php.net)
[![React](https://img.shields.io/badge/React-18-61dafb.svg)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178c6.svg)](https://www.typescriptlang.org)
[![Version](https://img.shields.io/badge/version-0.8.0-blue.svg)](CHANGELOG.md)

---

## 0.9.0 有什么新东西（未发布）

- **双因素认证** — 按用户启用 TOTP，注册时一次性发放 8 个恢复码，登录时校验动态口令。详见 [docs/mfa.md](docs/mfa.md)。
- **上传加固** — 文件名在落盘之前先做校验；除非显式放行，否则拒绝可执行的 SVG。
- **会话绑定** — 会话与创建时的客户端特征绑定并按周期轮换，仅窃取 Cookie 不足以登录。
- **备份校验** — 恢复前先按 manifest 与校验和验证备份，通过后才允许开始。
- **响应头策略** — 集中管理安全响应头（含 Content-Security-Policy），可新增、覆盖或关闭。
- **调度文档** — 说明运行周期性任务的三个层次，以及主机不提供 shell 时如何用 `webcron.php` 驱动。详见 [docs/scheduled-tasks.md](docs/scheduled-tasks.md)。

从 0.8.x 升级：[docs/migration-0.8-to-0.9.md](docs/migration-0.8-to-0.9.md) 逐项说明行为变更及其对应的配置项。

---

## 0.8.0 有什么新东西

- **多用户与 RBAC** — `users.json` 中的真实用户账户（admin / operator / viewer），用户名 + 密码登录，按用户偏好，锁定与密码过期。详见下方「多用户 —— 协作，而非多租户」。
- **基于路径的 ACL** — `path_allowlist` 将 viewer 限制在文件树的子集内；用户组（`groups.json`）的允许列表与用户自身的取并集。
- **公共 API Token** — 带作用域的 Bearer token，按 token 限流，SHA-256 存储（明文仅在创建时展示一次）。
- **双人审批** — 敏感操作（数据库导入、回收站清空、全部会话踢出、应用卸载）返回 `202 approval_pending`，需第二管理员在 60 分钟内批准。
- **邀请、信任设备、GDPR 式导出、按用户的通知偏好、权限提升**。
- **PHP 工具链页面** — Composer、OPcache、扩展、错误日志解析、PHP-FPM 池、黑盒基准、ini 差异 / JIT / include_path、进程查看、升级预检、autoload 审计。
- **运维** — 审计日志携带 `user_id`；`/api/audit/aggregate` 与按用户的活动流；真实的在线会话管理（列表 / 踢出，自我保护）。
- **WAF 安全系统** — Web 应用防火墙，带 SQL 注入 / XSS / 路径穿越检测、请求体检查、可配置规则集、实时拦截与审计日志。
- **应用商店** — 热门 PHP 应用（Discuz!、Emlog、Ghost、Halo、Laravel、Nextcloud、ThinkPHP、Vue 模板）一键安装，支持应用更新、克隆与卸载。
- **Web Shell** — 浏览器内终端模拟器，带命令历史、Tab 补全、彩色输出，以及锚定到 `files_root` 的类 chroot 沙箱。
- **网站监控** — 对用户自定义网站做在线率 / 响应时间 / SSL 证书监控，支持通知渠道告警与历史趋势图。
- **自定义错误页** — 部署在文档根目录的品牌化 403 / 404 / 500 / 503 页面，Markdown 编辑并实时预览。
- **日志分析** — Apache / Nginx 访问日志解析器，含热门页面、来源、状态码分布、机器人检测、地理 IP 汇总与慢请求排行。
- **安全加固** — 更深的路径穿越防御、认证绕过缓解、配置注入防护，以及更多暴力破解锁定维度。

完整列表见 [CHANGELOG.md](CHANGELOG.md)，破坏性变更见 [docs/migration-0.7-to-0.8.md](docs/migration-0.7-to-0.8.md)。

---

## 多用户 —— 协作，而非多租户

> ⚠️ **务必先读**
>
> - Go.js-Lite 的多用户是面向团队的**协作工具**：角色分离、审计与按用户偏好。
> - 若是**多个互不相关的客户**，请为**每个客户部署独立的面板实例**，**不要**用单个实例服务多个客户。
> - `path_allowlist` **不是租户边界**，只是 viewer 在共享文件树上的最小权限子集。同一实例的所有用户共享同一个 `files_root`、`config.php`、数据库连接、审计日志与监控数据。

| 角色 | 文件读取 | 文件写入 | 用户 / 会话管理 | PHP 工具链 | API Token |
|---|---|---|---|---|---|
| `admin` | 全部 | 全部 | 是 | 是 | 是 |
| `operator` | 全部 | 是 | 否 | 否 | 可创建受限 token |
| `viewer` | 仅白名单 | 否（除非临时提升） | 否 | 否 | 否 |

---

## 0.7.0 有什么新东西

- **REST 契约** — 在历史查询式 `/gojs/api?api=<action>` 之外，新增路径式 `/gojs/api/<action>`。两种形态都由 `router.php` 与 `.htaccess` 识别并派发到同一个 handler。查询式已于 0.8.0 弃用，见下方[「API 路由」](#api-路由)。
- **文件管理** — 每次保存留存历史快照，图片 / 视频 / 音频 / Markdown / PDF / CSV 浏览器内预览，批量操作，可选的单文件 AES-256-GCM 加密。
- **数据库管理** — 持久连接、慢查询日志、表结构快照、导出 SQL 时对敏感列做脱敏。
- **监控** — CPU / 内存 / 磁盘趋势图，支持 5 分钟 / 1 小时 / 24 小时窗口。
- **通知** — 新增 Microsoft Teams 与 Slack Incoming Webhook 适配器（在邮件 / SMTP / Webhook / 钉钉 / 飞书 / Telegram 之外）。
- **安全** — 暴力破解封禁升级为 IP + UA + 国家三重判定，按用户 / 按端点限流。
- **运维** — 所有写操作日志都带 `request_id` / `trace_id`。
- **诊断** — `/.gojs/diagnostics/export` 打包一份脱敏的运行时快照，便于支持排障。

0.7.0 仍然保持 Go.js-Lite 的轻量定位——单文件 PHP 入口（`api.php` + `router.php`）、模块化 `backend/`、内置 `webcron.php`，不依赖任何外部服务。完整变更与迁移说明见 [CHANGELOG.md](CHANGELOG.md)。

---

## 快速开始

### 普通用户（部署使用）

从 [Releases](https://github.com/YQteam-dyq/Go.js-Lite/releases) 下载最新的 `gojs-lite-VERSION.zip`，解压后把 **`gojs/` 目录**整个上传到你的 Web 根目录即可（目录名可任意，如 `gojs`、`panel`，或直接上传到根目录）。

- 面板入口：`https://你的域名/<部署目录>/`（例如部署到 `gojs` 目录则访问 `https://你的域名/gojs/`，首次访问自动进入安装向导）
- 面板文件全部独立于 Web 根目录，不影响你原有站点内容

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
- **[安全]** 安全可靠 — BCrypt 密码、CSRF 防护、路径越权防护、系统文件保护、WAF 防火墙
- **[WAF]** Web 应用防火墙 — SQL 注入 / XSS / 路径穿越检测、请求体检查、可配置规则集、实时拦截
- **[应用商店]** 一键装应用 — 安装热门 PHP 应用（Discuz!、Emlog、Ghost、Halo、Laravel、Nextcloud、ThinkPHP、Vue 模板），支持更新与克隆
- **[Web Shell]** 浏览器内终端 — 带命令历史、Tab 补全、彩色输出，沙箱锚定在 `files_root`
- **[文件]** 文件管理 — 在线浏览 / 编辑 / 上传 / 下载，支持权限修改
- **[压缩]** Zip / Tar 压缩解压 — 在线一键压缩、解压 zip / tar.gz 文件
- **[DB]** 数据库管理 — MySQL 连接管理、SQL 控制台、表结构浏览、**.sql 导入导出**
- **[日志]** PHP 错误日志 — 自动探测错误日志位置，按类型分类、实时查看
- **[日志分析]** 访问日志分析 — Apache / Nginx 访问日志解析，含热门页面、来源、状态码分布、机器人检测、地理 IP 汇总
- **[监控]** 网站监控 — 在线率 / 响应时间 / SSL 证书监控，支持告警与历史趋势图
- **[错误页]** 自定义错误页 — 品牌化 403 / 404 / 500 / 503 页面，Markdown 编辑并实时预览
- **[检查]** 配置体检 — PHP 安全 / 性能 / 兼容性一键检查
- **[磁盘]** 磁盘分析 — 可视化展示目录占用与大文件
- **[信息]** 系统信息 — PHP 信息、服务器环境、磁盘使用、内存使用监控、进程 CPU
- **[趋势]** 资源趋势 — 仪表盘内 CPU / 内存 / 磁盘趋势图
- **[防护]** 暴力破解封禁 — IP + UA + 国家三重判定
- **[2FA]** 双因素认证 — 按用户启用 TOTP，附 8 个一次性恢复码，登录时校验
- **[任务]** 定时任务 — 支持 `exec()` 与文件两种方式，另含面向无 shell 主机的内置 webcron 任务
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

> **部署前置要求**：主机需开启 **mod_rewrite** 且允许目录使用 `.htaccess`（多数虚拟主机默认开启）。`.htaccess` 使用相对路径重写，**部署在任意子目录都无需修改**；若遇到 403，请检查主机是否禁止了 `.htaccess` 生效。

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

> `.gojs/` 位于面板目录**内部**，与 `api.php` 同级：面板按 `dirname(__FILE__) . '/.gojs'` 解析该路径。重写规则会拒绝任何路径中含 `.gojs` 段的请求，因此该目录不会被 Web 直接访问。

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

### 大目录渲染

文件管理器用窗口化列表（`src/components/ui/VirtualList.tsx`）渲染超长目录：只挂载视口内的行，外加一小段 overscan 余量；滚动容器保留完整高度，因此滚动条行为正常。条目少于 60 的列表仍走普通标记，以免影响页内查找与 Tab 顺序。同一组件也用于网格视图，列数随容器宽度变化。

### 离线外壳与 Web Push

前端带一个 Service Worker（`public/sw.js`），预缓存应用外壳，并在导航失败时返回独立的离线页。API 请求从不缓存，因此面板不会展示过期的服务端数据。Web Push 注册是可选的：面板会探测缺失的能力（没有 Service Worker、非安全上下文、缺少 push manager），并在「通知偏好」中说明原因，而不是静默失败。Service Worker 只在生产构建中注册。

### 服务状态页

`/status` 是一个公开、不依赖会话的页面，用于报告面板可用性与各组件版本；当存在已认证会话时，还会补充存储占用、配置检查与阈值。它可以从命令面板跳转，且无需登录即可访问，因此适合做在线率监控。

### API 路由

面板接受两种 API 调用形态：

| 形态 | 示例 | 说明 |
|------|------|------|
| 路径式（推荐） | `/gojs/api/login` | 受支持的形态，由 `router.php` 与 `.htaccess` 派发。 |
| 查询式（已弃用） | `/gojs/api?api=login` | 历史默认形态，0.8.0 起弃用，1.0.0 移除。响应会带弃用响应头。 |

两种形态最终都进入同一个 `api.php` action handler，只有一条代码路径。移除时间表见 [docs/deprecations.md](docs/deprecations.md)。

---

## 文档索引

| 文档 | 内容 |
|---|---|
| [CHANGELOG.md](CHANGELOG.md) | 每个版本，以及各自的破坏性变更与迁移说明。 |
| [docs/api.md](docs/api.md) | 后端 API 参考：约定、端点总览表，以及每个端点的参考条目。 |
| [docs/deprecations.md](docs/deprecations.md) | 已弃用的内容、运行时的告知方式，以及 1.0.0 的移除时间表。 |
| [docs/migration-0.7-to-0.8.md](docs/migration-0.7-to-0.8.md) | 从单管理员面板升级到多用户。 |
| [docs/migration-0.8-to-0.9.md](docs/migration-0.8-to-0.9.md) | 升级到加固后的 0.9.0：上传、会话绑定、备份校验与响应头。 |
| [docs/scheduled-tasks.md](docs/scheduled-tasks.md) | 通过系统 crontab、或在没有 shell 时通过 `webcron.php` 运行周期性任务。 |
| [docs/mfa.md](docs/mfa.md) | 按用户的双因素认证：注册、登录校验与恢复码。 |
| [docs/database-query-builder.md](docs/database-query-builder.md) | 在面板里构建并执行查询，以及预览端点实际做了什么。 |
| [docs/waf_integration.md](docs/waf_integration.md) | Web 应用防火墙的规则、模式与集成点。 |
| [CONTRIBUTING.md](CONTRIBUTING.md) | 开发流程、语言政策，以及 PR 必须通过的检查。 |

---

## 功能预览

### 核心功能

| 功能 | 说明 | 状态 |
|------|------|------|
| 认证系统 | 安装引导、登录/登出、修改密码、会话超时、暴力破解防护 | 已支持 |
| 双因素认证 | 按用户启用 TOTP，附 8 个一次性恢复码，登录时校验 | 已支持 |
| 私密入口 | 带 token 的访问链接，隐藏面板存在 | 已支持 |
| 仪表盘 | 系统概览、磁盘使用、文件统计、最近修改文件 | 已支持 |
| 文件管理 | 目录浏览、文件编辑、上传/下载、创建/删除/重命名、权限修改、历史快照、内置预览、批量操作 | 已支持 |
| Zip / Tar 压缩解压 | 文件/目录压缩为 zip / tar.gz，解压任意归档 | 已支持 |
| 数据库管理 | MySQL 连接管理、数据库/表/列浏览、SQL 控制台 | 已支持 |
| SQL 导入导出 | 一键导出整库或单表、分块导入 .sql 文件 | 已支持 |
| PHP 错误日志 | 自动探测日志路径、按错误类型分类过滤、实时刷新 | 已支持 |
| 日志分析 | Apache / Nginx 访问日志解析，热门页面、来源、状态码分布、机器人检测 | 已支持 |
| 配置体检 | PHP 安全 / 性能 / 兼容性一键检查与建议 | 已支持 |
| 磁盘分析 | 目录大小占比可视化、大文件列表 | 已支持 |
| PHP 信息 | 版本、扩展、ini 配置、复制 php.ini 路径 | 已支持 |
| 系统信息 | 磁盘、负载、运行时间、内存使用、进程 CPU、Cron | 已支持 |
| 资源趋势 | CPU / 内存 / 磁盘趋势图 | 已支持 |
| 定时任务 | 支持 `exec()` 与文件两种方式，另含面向无 shell 主机的内置 webcron 任务 | 已支持 |
| 网站监控 | 在线率 / 响应时间 / SSL 证书监控，支持告警与趋势图 | 已支持 |
| 通知中心 | 邮件 / SMTP / Webhook / 钉钉 / 飞书 / Telegram / Microsoft Teams / Slack Incoming Webhook | 已支持 |
| 操作日志 | 所有写操作日志都带 `request_id` / `trace_id` | 已支持 |
| WAF 安全 | SQL 注入 / XSS / 路径穿越检测、可配置规则、实时拦截 | 已支持 |
| 应用商店 | PHP 应用一键安装，支持更新、克隆与卸载 | 已支持 |
| Web Shell | 浏览器内终端模拟器，带命令历史与彩色输出 | 已支持 |
| 自定义错误页 | 品牌化 403/404/500/503 页面，Markdown 编辑并实时预览 | 已支持 |
| 多用户与 RBAC | admin / operator / viewer 三种角色，用户组，基于路径的 ACL | 已支持 |
| 双人审批 | 敏感操作需第二管理员在 60 分钟内批准 | 已支持 |
| PHP 工具链 | Composer、OPcache、扩展、PHP-FPM、基准、升级预检 | 已支持 |
| API Token | 带作用域的 Bearer token，按 token 限流，SHA-256 存储 | 已支持 |
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
- **[WAF]** Web 应用防火墙 — SQL 注入、XSS、路径穿越、命令注入与配置注入检测，实时拦截并记录审计日志
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
