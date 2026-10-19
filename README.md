# 下载链接管理系统（DLM）

一套**零第三方依赖**的纯原生 PHP 下载链接管理系统，可直接 FTP 上传到共享 PHP 空间运行。

核心模型是「**存储库 + 属性 schema**」：每个存储库定义自己的一套属性（文本 / 日期 / 文件大小，可配置必填、可配置“仅允许指定值”约束），库内所有链接共用这套属性；链接的固定字段只有 `url`（同库内唯一）。数据以规范化的 EAV 结构存放在 MySQL 中，同时提供 WebUI 管理界面与 Bearer Token 鉴权的 HTTP API。

- 无 Composer、无框架、无前端构建工具链
- 单入口前端控制器 + 自研轻量路由 / 中间件
- 单用户登录：管理密码（哈希存 `config.php`）与 OIDC 单点登录（授权码 + PKCE）
- WebUI 全中文，响应式（宽屏表格 / 窄屏卡片），支持深色模式

---

## 目录

- [功能特性](#功能特性)
- [环境要求](#环境要求)
- [目录结构](#目录结构)
- [快速开始](#快速开始)
- [部署配置](#部署配置)
- [首次安装](#首次安装)
- [登录方式](#登录方式)
- [核心概念](#核心概念)
- [属性类型与取值规则](#属性类型与取值规则)
- [WebUI 使用说明](#webui-使用说明)
- [CSV 导入导出](#csv-导入导出)
- [HTTP API](#http-api)
- [健康检查](#健康检查)
- [数据库结构](#数据库结构)
- [配置文件](#配置文件)
- [安全设计](#安全设计)
- [测试](#测试)
- [常见问题](#常见问题)
- [开发约定](#开发约定)

---

## 功能特性

| 模块 | 能力 |
| --- | --- |
| 安装向导 | 数据库连接测试 → 自动建表 → 设置管理密码 / 可选启用 OIDC → 生成受保护的 `config.php` |
| 认证 | 单用户密码登录（哈希存 `config.php`，不入库）；OIDC 授权码 + PKCE（S256）+ JWKS 验签 + `sub` 白名单；全局 CSRF |
| 存储库 | 网格首页、增删改查、slug 自动生成与去重、级联删除、二次确认 |
| 属性 | 三种类型（文本 / 日期时间 / 文件大小）、必填、单选取值约束、可选值列表、排序 |
| 链接 | 单条新增/编辑/删除、批量删除、批量改某属性值、按属性过滤 + 关键词搜索、分页与排序、宽窄屏自适应 |
| CSV | 粘贴或上传导入（含前 5 行预览与逐行校验）、导出全部或选中（UTF-8 BOM，可再次导入） |
| APIKEY | 每 KEY 绑定单个存储库、只读/读写两档、可设过期时间、明文仅显示一次、库内仅存 sha256 |
| HTTP API | `GET /v1/schema`、`/v1/links` 增删改查、数组批量新增、过滤/排序/分页、统一响应格式 |
| 运维 | `GET /healthz` 健康检查（WEB + MySQL），返回 200 / 503，可直接用于负载均衡与容器探针 |
| 界面 | 手写 CSS + media query、深色模式（跟随系统 / 手动切换，localStorage 记忆）、自绘下拉菜单与文件选择控件 |

---

## 环境要求

| 项 | 要求 |
| --- | --- |
| PHP | **8.1+**（使用 `array_is_list()`、构造器属性提升、`readonly`、`match`） |
| 扩展 | `pdo_mysql`（必需）、`mbstring`（必需）、`openssl`（OIDC 验签必需）、`curl`（OIDC 请求优先使用，缺省时退回 stream） |
| 数据库 | MySQL **5.6+**（兼容 5.6，索引前缀按 180 字符设计），InnoDB + `utf8mb4` |
| Web 服务器 | Apache（`mod_rewrite`）或 Nginx，也支持 PHP 内置服务器 |
| 浏览器 | 支持 `localStorage` 与 `matchMedia` 的现代浏览器 |

> 系统不依赖任何第三方包，无需 `composer install`，也没有构建步骤。

---

## 目录结构

```
/
├── index.php                  # 唯一 Web 入口（前端控制器）
├── .htaccess                  # Apache 重写规则 + 保护 config.php / src / views / docs / logs
├── config.php                 # 安装向导生成：DB 凭据、app_secret、管理密码哈希、OIDC 配置（禁止提交）
├── .gitignore                 # 忽略 config.php、logs/、.vscode/ 等
├── README.md                  # 本文档
├── API.md                     # HTTP API 参考
├── src/
│   ├── bootstrap.php          # 常量、自动加载、配置加载、时区、会话、全局异常处理
│   ├── routes.php             # 汇总加载 web + api 路由表
│   ├── routes/
│   │   ├── web.php            # WebUI 路由
│   │   └── api.php            # /v1 HTTP API 路由
│   ├── Controllers/
│   │   ├── HealthController.php  # 健康检查（/healthz：WEB + MySQL）
│   │   ├── Web/               # Install / Auth / Repository / Attribute / Link / ApiKey
│   │   └── Api/               # ApiController（schema + links CRUD + 批量）
│   ├── Models/                # Repository / Attribute / Option / Link / ApiKey / Setting / ValueCodec
│   ├── Support/               # Router Request Response Db Config Auth Csrf ApiAuth ApiException
│   │                          # Helpers Validator View Flash Jwt Oidc OidcException
│   └── Install/
│       └── schema.sql         # 建表 SQL（安装向导读取执行）
├── views/                     # layout + 各页面模板（PHP 原生模板）
│   ├── layout.php             # 带导航的布局
│   ├── layout_blank.php       # 无导航布局（登录 / 安装）
│   ├── repositories/ attributes/ links/ keys/ auth/ install/
├── public/
│   ├── css/app.css            # 全站样式（含深色模式与响应式）
│   └── js/app.js              # 主题切换、弹窗、多选、CSV 预览、自绘控件
├── tests/
│   ├── unit.php               # 纯 PHP 单元脚本（类型校验、URL 规范化、大小解析等）
│   ├── oidc_jwt.php           # JWT 验签单测（RS/ES 全算法、篡改、alg=none、kid）
│   ├── dev-router.php         # PHP 内置服务器路由脚本（模拟 .htaccess 的目录保护）
│   ├── web_smoke.ps1          # WebUI HTTP 冒烟测试
│   ├── api_smoke.ps1          # HTTP API 冒烟测试
│   └── oidc_smoke.ps1         # OIDC 端到端冒烟测试（含最小 IdP）
├── docs/
│   └── compose/spec/download-link-manager.md   # 需求与设计规格书
└── logs/
    ├── php-error.log          # 运行日志（error_log 输出）
    └── cache/                 # OIDC JWKS 缓存（1 小时 TTL）
```

**命名空间**：`Dlm\` 映射到 `src/`，由 `src/bootstrap.php` 中的 `spl_autoload_register` 完成（类名即路径，如 `Dlm\Support\Router` → `src/Support/Router.php`）。

---

## 快速开始

### 1. 准备代码与数据库

把整个目录上传到站点根目录，创建一个 MySQL 数据库（`utf8mb4`），记下主机 / 端口 / 库名 / 账号 / 密码。

### 2. 配置 Web 服务器

**Apache**：确保启用 `mod_rewrite` 且 `AllowOverride All`（根目录 `.htaccess` 已包含全部规则，见 [部署配置](#部署配置)）。

**Nginx**：见下方 `try_files` 配置示例。

**本地快速体验**（PHP 内置服务器）：

```powershell
php -S 127.0.0.1:8000 tests/dev-router.php
```

`tests/dev-router.php` 会拦截 `src/`、`views/`、`docs/`、`logs/`、`tests/`、`config.php` 的直接访问，行为与 `.htaccess` 一致。

### 3. 访问并安装

浏览器打开 `http://127.0.0.1:8000/`，会自动跳转到 `/install` 安装向导：

1. 填写数据库连接信息（连接失败会直接报错，不会写入半成品配置）
2. 设置管理密码（至少 6 位）；或勾选「启用 OIDC 单点登录」并填写 issuer / client / secret / 回调地址 / `sub` 白名单
3. 提交后系统执行 `src/Install/schema.sql` 建表、写入 `settings`、生成 `config.php`
4. 自动跳转 `/login` 登录

> 已安装后再访问 `/install` 会被重定向到 `/login`。若想重新安装，先删除 `config.php`（数据库表可保留，建表语句均为 `CREATE TABLE IF NOT EXISTS`）。

### 4. 建库、建属性、建 KEY

1. 首页 → 「新建存储库」（如 `软件下载`）
2. 进入存储库 → 「属性」→ 新增属性，例如：`version`（文本）、`release_date`（日期时间）、`size`（文件大小）、`channel`（文本 + 仅允许指定值：稳定版 / 测试版）
3. 回到链接列表 → 「新增链接」或「批量导入」
4. 顶部导航「APIKEY」→ 为存储库创建只读或读写 KEY（明文只显示一次）

---

## 部署配置

### Apache

根目录 `.htaccess` 已包含：

```apache
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    # 禁止直接访问源码、模板、文档与版本库目录
    RewriteRule ^(?:src|views|docs|logs|\.git)(?:/|$) - [F,L]
    # 禁止直接访问配置文件
    RewriteRule ^config\.php$ - [F,L]
    # 前端控制器：非真实存在的文件/目录全部交给 index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

<Files "config.php">
    Require all denied
</Files>
```

若站点部署在子目录（如 `https://host/dlm/`），把 `RewriteBase /` 改为 `RewriteBase /dlm/` 即可，程序内部链接会基于 `SCRIPT_NAME` 自动推导前缀。

### Nginx

```nginx
server {
    listen 80;
    server_name dlm.example.com;
    root /var/www/dlm;
    index index.php;

    # 保护敏感路径
    location ~ ^/(src|views|docs|logs|tests|\.git)/ { deny all; }
    location = /config.php { deny all; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

### 无重写规则（降级模式）

若共享空间无法启用重写，请求也可通过以下两种方式路由，`Request::resolvePath()` 已内置支持：

- PATH_INFO：`https://host/index.php/repos/my-repo`
- 查询参数：`https://host/index.php?r=/repos/my-repo`

### 目录权限

- 站点根目录需**可写**，安装时才能生成 `config.php`
- `logs/` 需可写（错误日志与 JWKS 缓存）；程序会自动创建
- 生产环境建议安装完成后把根目录权限收紧为只读（`config.php` 除外）

> **上线提示**：根目录 `.htaccess` 已拒绝 `src/`、`views/`、`docs/`、`logs/`、`.git/` 与 `config.php` 的访问，但**未包含 `tests/`**。生产环境建议直接删除 `tests/` 与 `docs/` 目录，或在服务器配置中额外加一条 `deny all`（Nginx 示例已包含）。

---

## 首次安装

安装向导（`views/install/index.php` + `InstallController`）会校验并执行：

| 步骤 | 说明 |
| --- | --- |
| 输入校验 | 库名 / 用户必填；管理密码至少 6 位且两次一致；启用 OIDC 时校验 issuer、client_id、client_secret、回调地址、`scope` 必须包含 `openid`、`allowed_subs` 至少一个 |
| 连接测试 | `Db::connect()` 建立 PDO 连接并执行 `SELECT 1` |
| 建表 | 执行 `src/Install/schema.sql`（`CREATE TABLE IF NOT EXISTS`，可重复执行） |
| 写 settings | `schema_version=1`、`installed=1` |
| 生成 config.php | 写入 `app_secret`（随机 48 位 hex）、`admin.password_hash`（`password_hash()`）、可选 `oidc` 段 |

**注意**：若目录中已存在 `config.php`，向导会拒绝安装并提示先删除，避免覆盖现有配置。

---

## 登录方式

两种方式可独立或同时启用，登录状态保存在 `dlmsid` 会话 Cookie（`HttpOnly` + `SameSite=Lax`，HTTPS 下自动 `Secure`）。

### 1. 密码登录

- 密码哈希保存在 `config.php` 的 `admin.password_hash`
- 也支持临时写明文 `admin.password`（仅用于手工应急，`hash_equals` 比对）
- **忘记密码**：直接修改 `config.php` 中的 `admin.password_hash`（或临时写 `admin.password`）即可，无需数据库操作

```php
'admin' => [
    // 生成方式：php -r "echo password_hash('你的新密码', PASSWORD_DEFAULT);"
    'password_hash' => '$2y$12$....',
],
```

### 2. OIDC 单点登录

- 协议：Authorization Code + **PKCE（S256）**，零依赖实现（`Support\Oidc` + `Support\Jwt`）
- 流程：`GET /oidc/start`（生成 state / nonce / verifier 存入会话）→ 跳转 `authorization_endpoint` → `GET /oidc/callback` 校验 state → 用 code + verifier 换取 token → 验签 `id_token` → 校验 `iss / aud / azp / exp / iat / nonce` → 校验 `sub` 白名单 → 建立会话
- 验签：支持 `RS256/384/512`、`ES256/384/512`；公钥取自 `jwks_uri` 并按 `kid` 匹配；`alg=none` 与所有 `HS*` 一律拒绝；JWKS 缓存 1 小时（`logs/cache`）
- 白名单：`oidc.allowed_subs` 精确匹配（大小写敏感）；`Auth::check()` 每次请求都复核，**修改白名单后旧会话立即失效**
- 回调地址：优先用 `oidc.redirect_uri`；留空时按当前请求（支持 `X-Forwarded-Proto` / `X-Forwarded-Host`）推导 `/oidc/callback`

---

## 核心概念

| 概念 | 说明 |
| --- | --- |
| **存储库 Repository** | 一组下载链接的集合，拥有自己的属性 schema；`slug` 全局唯一，用于 URL |
| **属性 Attribute** | 属于某个存储库的字段定义：`label`（中文显示名）、`key`（机器名，同库唯一）、`type`、`required`、`constrained`、`sort_order` |
| **可选值 Option** | 当属性 `constrained=1` 时，链接只能取列表中的一个值（单选） |
| **链接 Link** | 固定字段只有 `url`（必填，同库唯一，按规范化后的 sha256 判定）与 `created_at` / `updated_at`；其余信息一律以属性值形式存在 |
| **属性值 LinkValue** | 某链接在某属性上的取值，按类型分列存储（`value_text` / `value_date` / `value_num`） |
| **APIKEY** | 绑定单个存储库，`read` 或 `write` 权限，可设 `expires_at`，库内只存 `sha256` 哈希与前缀 |

**级联关系**：删除存储库会级联删除其属性、可选值、链接、属性值与 APIKEY；删除属性会级联删除该属性在所有链接上的取值。所有删除操作在 WebUI 均有二次确认弹窗。

---

## 属性类型与取值规则

| 类型 | 存储列 | 输入格式 | 校验规则 |
| --- | --- | --- | --- |
| `text` 文本 | `value_text` (TEXT) | 任意字符串 | 长度 ≤ 65535；`constrained=1` 时必须命中可选值列表 |
| `date` 日期时间 | `value_date` (DATETIME) | `YYYY-MM-DD`、`YYYY-MM-DD HH:MM`、`YYYY-MM-DD HH:MM:SS` | 格式不合法则拒绝；WebUI 使用 `datetime-local`（`T` 会被替换为空格） |
| `filesize` 文件大小 | `value_num` (BIGINT) | 纯字节数 `1024`，或 `1.5KB` / `2MB` / `1GB` / `1TB`（大小写不敏感） | 必须能解析为非负整数；展示时自动格式化为 `1.5 KB` 形式 |

补充规则：

- 属性 `key` 必须匹配 `^[a-z][a-z0-9_-]*$`，长度 ≤ 50，同库内唯一
- 属性 `label` 必填，长度 ≤ 100
- 只有 `text` 类型支持 `constrained`（“仅允许指定值”）；启用时必须至少提供一个可选值
- 可选值单条截断至 180 字符，去重并保持输入顺序
- `required=1` 的属性在**创建**链接时必须提供；**更新**时若未出现在提交数据中则保持原值，若显式提交 `null` / 空串则报「为必填项」（即必填属性不能清空）

---

## WebUI 使用说明

### 页面与路由

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/healthz` | 健康检查（WEB + MySQL，无需登录，见 [健康检查](#健康检查)） |
| GET | `/install` | 安装向导（已安装则跳 `/login`） |
| POST | `/install` | 执行安装 |
| GET | `/login` | 登录页 |
| POST | `/login` | 密码登录 |
| GET | `/oidc/start` | 发起 OIDC 登录 |
| GET | `/oidc/callback` | OIDC 回调 |
| POST | `/logout` | 退出登录 |
| GET | `/` | 存储库网格首页 |
| GET | `/repos/new` | 新建存储库表单 |
| POST | `/repos` | 创建存储库 |
| GET | `/repos/{slug}` | 链接列表 |
| GET | `/repos/{slug}/edit` | 编辑存储库表单 |
| POST | `/repos/{slug}` | 更新存储库 |
| POST | `/repos/{slug}/delete` | 删除存储库（级联） |
| POST | `/repos/{slug}/links` | 新增链接 |
| POST | `/repos/{slug}/links/bulk` | 批量操作（`delete` / `set_attr` / `export`） |
| POST | `/repos/{slug}/links/{id}` | 更新链接 |
| POST | `/repos/{slug}/links/{id}/delete` | 删除链接 |
| GET | `/repos/{slug}/export` | 导出 CSV（全部，或 `?ids=1,2,3` 仅选中） |
| GET | `/repos/{slug}/import` | 批量导入页 |
| POST | `/repos/{slug}/import/preview` | CSV 预览（返回 JSON，供前端渲染） |
| POST | `/repos/{slug}/import` | 执行导入 |
| GET | `/repos/{slug}/attributes` | 属性管理 |
| POST | `/repos/{slug}/attributes` | 新增属性 |
| POST | `/repos/{slug}/attributes/{id}` | 更新属性 |
| POST | `/repos/{slug}/attributes/{id}/delete` | 删除属性 |
| GET | `/keys` | APIKEY 列表与创建 |
| POST | `/keys` | 创建 APIKEY（明文仅本次响应显示） |
| POST | `/keys/{id}/delete` | 吊销 APIKEY |

> 未挂 `admin` 中间件的只有：`/healthz`、`/install`（GET/POST）、`/login`（GET/POST）、`/oidc/start`、`/oidc/callback`、`/logout`。其余页面路由都要求已登录；所有 POST 都挂了 `csrf` 中间件（校验失败返回 **419**）。

### 链接列表的过滤、搜索、排序与分页

**过滤**（GET 参数，可组合，多条件为 AND）：

| 场景 | 写法 | 说明 |
| --- | --- | --- |
| 文本 / 受约束属性 | `filter[channel]=稳定版` | 精确匹配 |
| 文本子串 | `filter[name_contains]=beta` | `LIKE %value%` |
| 日期 | `filter[release_date]=2026-01-01T12:00` | 精确匹配到秒（控件提交的 `T` 会被转成空格） |
| 日期区间 | `filter[release_date_gte]=2026-01-01&filter[release_date_lte]=2026-12-31` | `>=` / `<=` |
| 文件大小区间 | `filter[size_gte]=100MB&filter[size_lte]=5GB` | 自动解析单位后按字节比较 |

支持的操作符后缀：`_contains`、`_gte`、`_lte`、`_gt`、`_lt`；无后缀即 `eq`。WebUI 会**静默忽略**未知属性和空值条件，不报错。

**关键词**：`q=关键词` 对 `url` 与所有文本属性值做子串匹配。

**排序**：`sort=created_at` / `-created_at`（默认）/ `updated_at` / `-updated_at` / `url` / `<attr_key>`（如 `sort=-size`）。前缀 `-` 表示倒序；未知排序键回退为 `created_at DESC`。

**分页**：`page`（默认 1）、`page_size`（WebUI 仅允许 **10 / 20 / 50 / 100**，非法值回落 20；API 允许 1–200）。切换每页条数会重置到第 1 页并保留过滤/搜索/排序参数。

### 批量操作

在链接列表中勾选若干行后，从下拉菜单选择：

- **批量删除**：`Link::deleteMany()`，仅删除属于当前存储库的 ID
- **批量修改属性**：选择属性 + 填新值；留空表示清除该属性值（若该属性为必填，清空会因校验失败而整批不生效）
- **批量导出选中**：跳转到 `export?ids=...` 下载 CSV

> 批量修改属性时，新值会按属性类型统一校验一次（受约束属性必须在选项内、日期/大小格式必须合法），通过后对所选链接逐条写入。

### 界面细节

- 宽屏（>760px）显示表格，窄屏切换为卡片布局
- 深色模式：右上角按钮循环切换「跟随系统 → 深色 → 浅色」，偏好存 `localStorage` 的 `dlm-theme`
- 自绘下拉菜单与文件选择控件为渐进增强，底层仍是原生 `<select>` / `<input type="file">`，不影响表单提交与校验
- 危险操作（删除、吊销）使用自绘确认弹窗（`data-confirm`）

---

## CSV 导入导出

### 格式

首行为表头，**必须包含 `url` 列**，其余列名对应属性 `key`（不是 `label`）：

```csv
url,channel,version,release_date,size
https://example.com/app-1.0.zip,稳定版,1.0.0,2026-01-01 12:00,104857600
https://example.com/app-1.1-beta.zip,测试版,1.1.0-beta,2026-02-15 09:30,110MB
```

- 日期列：`YYYY-MM-DD HH:MM` 或 `YYYY-MM-DD HH:MM:SS`
- 文件大小列：字节数或带单位（`100MB` / `1.5GB`）
- 导入时会自动去除 UTF-8 BOM；空白行忽略
- 表头中的未知列会被忽略并在预览中提示；缺少 `url` 列则直接拒绝导入

### 导入流程

1. 进入「批量导入」，粘贴 CSV 或上传 `.csv` 文件
2. 点「预览」→ 前端 `POST /repos/{slug}/import/preview` 返回前 5 行的解析结果（列名、已知/未知列、总行数、逐行校验错误）
3. 点「导入」提交正式执行，结果按三类统计并提示：
   - **成功**：新建链接
   - **跳过**：URL 非法或 URL 已存在
   - **失败**：其他校验错误（必填缺失、受约束取值非法、日期/大小格式错误等），最多展示 5 条原因

### 导出

- 「全部导出」：`GET /repos/{slug}/export`，服务端按 200 条/页分批取全量
- 「导出选中」：`GET /repos/{slug}/export?ids=1,2,3`
- 文件名为 `<slug>-links.csv` 或 `<slug>-links-selected.csv`，带 UTF-8 BOM（Excel 直接打开不乱码），可直接再导入
- 日期导出为 `YYYY-MM-DD HH:MM`，文件大小导出为纯字节数（保证往返无损）

---

## HTTP API

完整的请求/响应示例、错误码与调用流程见 **[API.md](API.md)**。要点：

- 前缀 `/v1`，Bearer Token 鉴权：`Authorization: Bearer dlm_xxxxxxxx`
- 统一响应：`{"code":0,"message":"ok","data":...}`
- 端点：`GET /v1/schema`、`GET /v1/links`、`POST /v1/links`（对象=单条，数组=批量）、`GET /v1/links/{id}`、`PATCH /v1/links/{id}`、`DELETE /v1/links/{id}`
- 每个 KEY 只能访问其绑定的存储库；只读 KEY 的写操作返回 403

---

## 健康检查

`GET /healthz` 供负载均衡、容器探针与监控使用，**无需登录**，也不需要 CSRF token。

它同时检查两件事：

| 检查项 | 判定方式 |
| --- | --- |
| `web` | 请求能被 PHP 正常执行并路由到控制器（即 WEB 服务可用），响应中带上 `php_version` |
| `database` | 用 `config.php` 的 `db` 配置建立新 PDO 连接并执行 `SELECT 1`，超时 3 秒；成功时带上 `server_version` 与耗时 |

### 响应

全部通过 → **200**：

```json
{
  "status": "ok",
  "checks": {
    "web": { "status": "ok", "php_version": "8.2.12" },
    "database": { "status": "ok", "server_version": "5.7.44", "duration_ms": 1.83 }
  },
  "timestamp": "2026-01-01T12:00:00+08:00"
}
```

任一失败 → **503**（`status` 为 `fail`，失败的检查项带 `message`）：

```json
{
  "status": "fail",
  "checks": {
    "web": { "status": "ok", "php_version": "8.2.12" },
    "database": { "status": "fail", "message": "数据库连接失败", "duration_ms": 3002.15 }
  },
  "timestamp": "2026-01-01T12:00:00+08:00"
}
```

### 行为说明

- 返回体**不套用** `/v1` 的 `{code,message,data}` 信封，便于监控系统直接读 `status`
- 数据库失败的具体原因（可能含账号、主机等信息）只写入 `logs/php-error.log`，响应里只给通用提示
- 系统**未安装**（无 `config.php`）时同样返回 **503**，`database` 项提示「系统尚未安装完成」，而不会像其他页面那样 302 跳转 `/install`（避免探针把 302 当成正常）
- 该路由**不建立会话**，高频探测不会产生 session 文件
- 未开启任何缓存（`Cache-Control: no-store`）

### 探针配置示例

```nginx
location = /healthz {
    access_log off;
    proxy_pass http://127.0.0.1:8000;
}
```

```yaml
# docker-compose / Kubernetes
healthcheck:
  test: ["CMD", "curl", "-fsS", "http://127.0.0.1:8000/healthz"]
  interval: 30s
  timeout: 5s
  retries: 3
```

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://dlm.example.com/healthz
```

---

## 数据库结构

MySQL 5.6 兼容，InnoDB + `utf8mb4_unicode_ci`，索引前缀按 180 字符设计。完整 DDL 见 `src/Install/schema.sql`。

| 表 | 关键字段 | 说明 |
| --- | --- | --- |
| `repositories` | `id`, `name`, `slug` (UNIQUE), `description`, `created_at`, `updated_at` | 存储库 |
| `attributes` | `id`, `repository_id` (FK CASCADE), `key`, `label`, `type` ENUM, `required`, `constrained`, `sort_order`, UNIQUE(`repository_id`,`key`) | 属性定义 |
| `attribute_options` | `id`, `attribute_id` (FK CASCADE), `value`, `sort_order`, UNIQUE(`attribute_id`,`value`(180)) | 受约束属性的可选值 |
| `links` | `id`, `repository_id` (FK CASCADE), `url` VARCHAR(1500), `url_hash` CHAR(64), UNIQUE(`repository_id`,`url_hash`) | 链接，`url_hash` = `sha256(规范化 url)` |
| `link_values` | `link_id` + `attribute_id` (复合主键), `value_text` TEXT, `value_date` DATETIME, `value_num` BIGINT | EAV 属性值，按类型分列；三个类型列均建有 `(attribute_id, col)` 索引 |
| `api_keys` | `id`, `repository_id` (FK CASCADE), `name`, `key_hash` CHAR(64) UNIQUE, `key_prefix`, `permissions` ENUM('read','write'), `expires_at` | API 密钥，只存哈希 |
| `settings` | `key` (PK), `value` | `schema_version`、`installed` 等 |

**URL 唯一性**：`Helpers::urlHash()` 先做规范化再取 sha256。http/https 规范化 scheme 与 host 小写、保留 port / path / query / fragment，因此 `HTTP://Example.COM/Path` 与 `http://example.com/Path` 视为同一 URL；ed2k 整串转小写；magnet 解析 `&` 参数、键与值 urldecode 后按 `key` / `value` 排序重建，`xt` 的哈希部分转小写，因此参数顺序不同或 btih 大小写不同都视为同一链接。

---

## 配置文件

`config.php` 由安装向导生成，**禁止提交到版本库**（已在 `.gitignore` 中忽略，并被 `.htaccess` / Nginx 规则拒绝 Web 访问）。文件首行有 `if (!defined('DLM_APP'))` 守卫，即使被直接包含也不会泄露内容。

```php
<?php
if (!defined('DLM_APP')) { http_response_code(403); exit; }
return [
    'installed'  => true,
    'app_secret' => '<随机 48 位 hex>',
    'timezone'   => 'Asia/Shanghai',

    'admin' => [
        // 推荐：password_hash() 结果
        'password_hash' => '$2y$12$...',
        // 应急备用：'password' => '明文密码',
    ],

    // 可选：OIDC 登录
    'oidc' => [
        'enabled'       => true,
        'issuer'        => 'https://auth.example.com',
        'client_id'     => '...',
        'client_secret' => '...',
        'redirect_uri'  => 'https://dlm.example.com/oidc/callback', // 可留空，按请求推导
        'scope'         => 'openid email profile',                  // 必须包含 openid
        'allowed_subs'  => ['248289761001'],                        // 也兼容单个字符串 oidc.allowed_sub
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'dlm',
        'user'    => 'dlm',
        'pass'    => '...',
        'charset' => 'utf8mb4',
    ],
];
```

`Support\Config` 提供点号取值：`Config::get('db.host')`、`Config::installed()`、`Config::oidc()`、`Config::oidcAllowedSubs()`。

---

## 安全设计

- **SQL**：全部走 PDO 预处理（`ATTR_EMULATE_PREPARES = false`），禁止字符串拼接用户输入；排序/列名走白名单映射
- **XSS**：模板输出统一经 `Helpers::e()`（`htmlspecialchars` + `ENT_QUOTES`）；前端动态渲染 CSV 预览时使用 `escapeHtml()`
- **CSRF**：所有 Web POST 路由挂 `csrf` 中间件，token 存会话，支持 `_token` 字段或 `X-CSRF-Token` 头；失败返回 419
- **会话**：`HttpOnly` + `SameSite=Lax`，HTTPS 下 `Secure`；登录成功与 OIDC 登录均 `session_regenerate_id(true)` 防固定攻击
- **密码**：`password_hash()` / `password_verify()`，哈希存于受保护的 `config.php`，不入库
- **APIKEY**：`dlm_` + 40 位 hex，库内只存 `sha256` 哈希与前 12 位前缀；支持过期时间；吊销即删除记录
- **URL 白名单**：下载链接仅允许 `http` / `https` / `ed2k` / `magnet`，拒绝 `javascript:`、`ftp:`、`data:` 等；ed2k 必须匹配 `ed2k://|file|名称|大小|32 位哈希|/`（或 server 形式），magnet 必须带合法的 `xt=urn:btih:...`（40 位 hex 或 32 位 base32）。OIDC 的 issuer / redirect_uri 仍只允许 `http` / `https`
- **越库隔离**：所有模型方法都带 `repository_id` 条件，API KEY 只能操作其绑定存储库；跨库 ID 返回 404
- **OIDC**：state / nonce 一次性消费，强制 PKCE S256，`id_token` 必须验签并校验 `iss` / `aud` / `azp` / `exp` / `iat` / `nonce`，`sub` 必须命中白名单；`client_secret` 只存 `config.php`
- **目录保护**：`config.php`、`src/`、`views/`、`docs/`、`logs/`、`.git/` 均禁止直接 Web 访问；关闭目录索引
- **错误处理**：`display_errors=0`，异常写入 `logs/php-error.log`；API 请求返回 JSON 错误，页面请求返回 500 页面，不泄露堆栈

---

## 测试

项目不带 PHPUnit，使用自带的纯 PHP 脚本与 PowerShell HTTP 冒烟脚本。

### 单元测试（无需数据库）

```powershell
php tests/unit.php      # 31 项：文件大小解析/格式化、URL 校验与规范化、日期解析、三类型校验、EAV 列映射
php tests/oidc_jwt.php  # 14 项：RS/ES 六种算法验签、payload 篡改拒绝、alg=none 拒绝、未知 alg 拒绝、kid 匹配与缺失
```

两者输出形如 `汇总：PASS=31 FAIL=0` / `汇总：PASS=14 FAIL=0`，有失败时退出码非 0。

> 这两个脚本只加载 `src/bootstrap.php`，**不会连接数据库**（`Config::load()` 在 `config.php` 缺失时也会正常跳过），因此可在任意有 PHP 的机器上直接运行。

### HTTP 冒烟测试（需先起服务与数据库）

```powershell
# 1) 启动服务
php -S 127.0.0.1:8000 tests/dev-router.php

# 2) WebUI 流程：健康检查 → 登录 → 建库 → 建属性 → 建链接 → 过滤 → CSV 导出/预览 → 批量操作 → 建 KEY → 退出
powershell -File tests/web_smoke.ps1 -BaseUrl http://127.0.0.1:8000 -Password '<管理密码>'

# 3) HTTP API：401/403 鉴权、schema、单条/批量创建、重复 URL、过滤分页、PATCH、DELETE
powershell -File tests/api_smoke.ps1 -BaseUrl http://127.0.0.1:8000 -Token 'dlm_xxx' -ReadOnlyToken 'dlm_yyy'

# 4) OIDC 端到端（脚本内起最小 IdP：发现文档 / JWKS / authorize / token + PKCE 校验）
powershell -File tests/oidc_smoke.ps1
```

> 冒烟脚本会创建带时间戳与随机串的测试数据，可重复运行；`api_smoke.ps1` 会在结束时清理自己创建的链接。

---

## 常见问题

**Q：访问首页直接跳到 `/install`？**
系统未安装（`config.php` 不存在或 `installed=false`）。完成安装向导即可。

**Q：安装时报「config.php 已存在」？**
出于安全考虑，向导不会覆盖已有配置。删除 `config.php` 后重新安装（表结构为 `IF NOT EXISTS`，不会丢数据）。

**Q：登录页提示「配置文件中尚未设置管理密码」？**
`config.php` 里没有 `admin.password_hash` 也没有 `admin.password`，并且没有启用 OIDC。此时无法登录，需手动补上密码哈希或配置 OIDC。

> 只配置了 OIDC（没有管理密码）属于正常用法：登录页只显示「使用 OIDC 登录」按钮，不会出现上述提示。

**Q：忘记管理密码？**
编辑 `config.php`，把 `admin.password_hash` 换成新的哈希：

```powershell
php -r "echo password_hash('新密码', PASSWORD_DEFAULT), PHP_EOL;"
```

**Q：OIDC 登录报「state 校验失败」或「nonce 不匹配」？**
会话在跳转过程中丢失（常见于回调域名与登录域名不一致、Cookie 域/`SameSite` 限制、或代理未透传 `X-Forwarded-Proto`）。请确认 `oidc.redirect_uri` 与 IdP 登记值完全一致，并保持访问域名一致。

**Q：OIDC 登录报「该账号未被授权访问本系统」？**
`sub` 不在 `oidc.allowed_subs` 白名单中（精确匹配、大小写敏感）。白名单修改后旧会话会立即失效，需要重新登录。

**Q：接口一直返回 401？**
检查是否使用 `Authorization: Bearer <key>`（注意 `Bearer` 后有空格），KEY 是否已被吊销或已过期，以及 KEY 是否属于目标存储库。

**Q：批量导入后「跳过」了很多行？**
「跳过」表示 URL 非法或该 URL 在**同一存储库内**已存在（去重按规范化后的 sha256 判定）。重复数据不会被重复写入。

**Q：修改属性 `key` 后原有数据去哪了？**
`key` 是属性值与 CSV 表头的关联标识，修改 `key` 后旧值仍按 `attribute_id` 存储、不会丢失，但 CSV 表头需同步使用新的 `key`。

**Q：删除属性后数据还在吗？**
不在了。`link_values` 通过外键 `ON DELETE CASCADE` 随属性删除而清除，操作前请确认（WebUI 有二次确认提示）。

**Q：如何换机器 / 备份？**
备份两处即可：数据库（`mysqldump`）+ `config.php`（含密钥与密码哈希）。代码目录无需改动。

---

## 开发约定

### 新增一个 Web 页面

1. 在 `src/Controllers/Web/` 新建或扩展控制器，方法签名固定为 `public function xxx(Request $request, ...$routeParams): void`
2. 在 `src/routes/web.php` 注册路由，按需挂中间件：

```php
$router->get('/repos/{slug}/xxx', [XxxController::class, 'index'], ['middleware' => 'admin']);
$router->post('/repos/{slug}/xxx', [XxxController::class, 'store'], ['middleware' => ['admin', 'csrf']]);
```

3. 在 `views/` 下新建模板，用 `View::render('repos/xxx', [...])` 渲染；需要无导航布局时传第三个参数 `'layout_blank'`

### 中间件

`Router::runMiddleware()` 目前支持三个名称（在 `src/routes/*.php` 的 `middleware` 选项中使用，可传字符串或数组）：

| 名称 | 作用 |
| --- | --- |
| `admin` | 未登录跳转 `/login`（并记录 intended 地址） |
| `csrf` | 校验 CSRF token，失败返回 419 |
| `api` | 解析 Bearer Token，绑定存储库与权限，失败抛 `ApiException` |

### 新增一个 API 端点

1. 在 `src/Controllers/Api/ApiController.php` 增加方法，用 `ApiAuth::repositoryId()` 拿到当前 KEY 绑定的存储库 ID，写操作用 `ApiAuth::requireWrite()` 校验权限
2. 在 `src/routes/api.php` 注册，统一挂 `['middleware' => 'api']`
3. 用 `Response::apiOk($data)` / `throw ApiException::xxx()` 输出，保持响应格式一致
4. 在 `API.md` 补充文档

### 代码风格

- 全文件 `declare(strict_types=1)`，类用 `final`，命名空间 `Dlm\` 首字母大写目录
- 业务类几乎都是静态方法（`Support\*`、`Models\*`）；控制器由路由即时 `new`，无 DI 容器
- 唯一的跨请求状态在会话与 `ApiAuth` 的静态字段中（每次请求重置）
- 中文注释与中文错误消息；用户可见文本一律中文
- 视图只做展示，业务逻辑放模型/控制器
- 修改后建议跑：`php -l`（全部文件）→ `php tests/unit.php` → `php tests/oidc_jwt.php` → 两个冒烟脚本
