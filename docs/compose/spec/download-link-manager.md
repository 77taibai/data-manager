---
feature: download-link-manager
status: delivered
updated: 2026-10-17
branch: main
commits: e56b986..fd60399
---

# 下载链接管理系统

## Report

**What was built** — 一套零依赖的纯原生 PHP 下载链接管理系统，可直接上传到共享 PHP 空间。核心是「存储库 + 属性 schema」模型：每个存储库定义自己的属性（文本 / 日期 / 文件大小，可配置必填、可配置“仅允许指定值”单选约束），库内链接共用同一套属性，固定字段只有 URL（同库内唯一）。数据以规范化 EAV 存于 MySQL 5.6（`repositories / attributes / attribute_options / links / link_values / api_keys / admin_users / settings`）。

**功能**：首次访问安装向导（测试连接 → 建表 → 建管理员 → 生成受保护的 config.php）；单管理员 Session 登录 + 全局 CSRF；存储库网格首页与 CRUD（级联删除、二次确认）；属性管理；链接列表（宽屏表格 / 窄屏卡片自适应、按属性生成过滤控件 + 关键词搜索、分页排序、多选批量删除与批量改属性）；CSV 批量导入（粘贴/上传 + 前 N 行预览）与导出（全部/选中，含 UTF-8 BOM，可再导入）；APIKEY 管理（每 KEY 绑定单库、只读/读写两档、可过期、明文仅显示一次、sha256 存储）。HTTP API 前缀 `/v1`（`schema` 只读、`links` 增删改查、数组批量新增、`filter[key]`/`filter[key_gte|lte]`/`q`/分页排序、统一 `{code,message,data}` 响应与状态码），Bearer Token 鉴权、越库隔离、只读 KEY 写操作 403。深色模式支持跟随系统与手动切换（localStorage）。

**Verification** — 命令与结果：
- `php -l` 递归全部 46 个 `.php` → 0 错误
- `php tests/unit.php` → PASS 31 / FAIL 0
- `tests/web_smoke.ps1`（真实 HTTP）→ PASS 16 / FAIL 0
- `tests/api_smoke.ps1` → PASS 10 / FAIL 0
- 额外端到端（CSRF 419、config/src 403、三类型属性、过滤、CSV 往返、重复导入跳过、批量删除、跨库 404）→ PASS 12 / FAIL 0
- 验证环境：本机 PHP 8.5.11 + MySQL 5.6.51（由 php-manager / mysql-manager 启动，验证后已关闭）

**Journey log** —
- 采用子代理拆分：主代理搭建骨架 + 模型/安装/认证（接口契约），再并行委派 API 层与 WebUI 层（文件集不重叠），最后由主代理集成、验证与委派独立审查。
- 真实缺陷 1：`Link::query` 复用了命名占位符 `:qtext`，PDO 原生预处理报 HY093；改用两个占位符修复。
- 真实缺陷 2（审查发现）：管理页 GET 路由漏配 `admin` 中间件，导致未鉴权可读；已补中间件并加回归测试。
- 真实缺陷 3（审查发现）：文本属性值被无条件 `str_replace('T',' ',...)` 破坏；改为仅 `date` 类型转换，并加回归测试。
- 工具经验：PowerShell 5.1 的 `-MaximumRedirection 0` 抛异常且无 Response，应改用 `AllowAutoRedirect=$false`；双引号字符串中 `$var?` 会被当作变量名的一部分，应写 `${var}`；切勿用 `Get-Content`/`Set-Content` 往返处理中文 UTF-8 源文件（会破坏编码）。


## [S1] Problem

需要一个运行在共享 PHP 空间的下载链接管理系统：把不同类型的下载链接归类到不同的「存储库」，每个存储库有自己的一套属性定义；提供带 APIKEY 的 HTTP API 供脚本增删改查；WebUI 支持单条/批量增删改、按属性过滤、CSV 导入导出，并在电脑端与手机端都可用。

约束：

- 运行环境：PHP 8.5 + MySQL 5.6.51。
- 零第三方依赖（无 Composer），纯原生 PHP，可直接 FTP 上传到共享空间。
- 数据库访问统一走 PDO(mysql) + 预处理语句。
- MySQL 5.6 无 JSON 类型、无 `innodb_default_row_format` 保证，索引前缀按 180 字符（utf8mb4，COMPACT 行格式 767 字节上限）设计；`links.url` 取 `VARCHAR(1500)` 以适配行大小限制。

## [S2] Design

### [S2.1] 核心概念

- **存储库 (Repository)**：一组下载链接的集合，拥有自己的属性 schema。库内所有链接共享同一套属性定义。
- **属性 (Attribute)**：属于某个存储库的定义，含中文显示名 `label`、唯一机器名 `key`、类型、是否必填、是否受可选值约束、排序。
- **属性值可选列表 (Option)**：当属性受约束时，链接只能取此列表中的值（单选）。
- **链接 (Link)**：固定字段只有 `url`（必需）与系统时间戳；其余信息一律以属性值形式存在。
- **属性值 (LinkValue)**：某链接在某属性上的取值，按类型分列存储（文本/日期/数值）。
- **APIKEY**：绑定单个存储库，权限为只读或读写，可设过期时间，可附名称备注。

### [S2.2] 目录与入口

```
/
├── index.php                # 唯一 Web 入口（前端控制器）
├── .htaccess                # Apache 重写 + 保护 config.php / src / views
├── config.php               # 安装向导生成：DB 凭据 + app_secret（禁止提交）
├── .gitignore               # 忽略 config.php 与临时文件
├── src/
│   ├── bootstrap.php        # 加载配置、注册自动加载、初始化 DB、路由
│   ├── routes.php           # 路由表
│   ├── support/             # Db Request Response Router Csrf Auth Validator View Log
│   ├── models/              # Repository Attribute Option Link ApiKey AdminUser Setting
│   ├── controllers/
│   │   ├── api/             # ApiController + 各资源 Action
│   │   └── web/             # 安装、登录、存储库、属性、链接、KEY 控制器
│   └── install/schema.sql   # 建表 SQL（安装向导读取执行）
├── views/                   # layout + 各页面 PHP 模板
└── public/                  # 静态资源
    ├── css/app.css
    └── js/app.js
```

- 路由：`.htaccess` 将非真实文件/目录的请求重写到 `index.php`。
- 降级：无重写时支持 `index.php/路径` 的 PATH_INFO 与 `?r=/路径` 查询参数。
- 保护：`config.php`、`src/`、`views/`、`docs/` 通过 `.htaccess` 拒绝直接 Web 访问。

### [S2.3] 数据库结构（MySQL 5.6，InnoDB，utf8mb4）

- `repositories(id PK, name VARCHAR(100), slug VARCHAR(100) UNIQUE, description TEXT NULL, created_at DATETIME, updated_at DATETIME)`
- `attributes(id PK, repository_id FK→repositories ON DELETE CASCADE, `key` VARCHAR(50), label VARCHAR(100), type ENUM('text','date','filesize'), required TINYINT(1) DEFAULT 0, constrained TINYINT(1) DEFAULT 0, sort_order INT DEFAULT 0, created_at, updated_at, UNIQUE(repository_id, `key`))`
- `attribute_options(id PK, attribute_id FK→attributes ON DELETE CASCADE, value VARCHAR(255), sort_order INT DEFAULT 0, UNIQUE(attribute_id, value(180)))`
- `links(id PK, repository_id FK→repositories ON DELETE CASCADE, url VARCHAR(1500), url_hash CHAR(64), created_at, updated_at, UNIQUE(repository_id, url_hash))` — `url_hash` = sha256(规范化 url)。
- `link_values(link_id FK→links ON DELETE CASCADE, attribute_id FK→attributes ON DELETE CASCADE, value_text TEXT NULL, value_date DATETIME NULL, value_num BIGINT NULL, PRIMARY KEY(link_id, attribute_id), INDEX(attribute_id, value_text(180)), INDEX(attribute_id, value_date), INDEX(attribute_id, value_num))`
- `api_keys(id PK, repository_id FK→repositories ON DELETE CASCADE, name VARCHAR(100) NULL, key_hash CHAR(64) UNIQUE, key_prefix VARCHAR(16), permissions ENUM('read','write') DEFAULT 'read', expires_at DATETIME NULL, created_at DATETIME)`
- `admin_users(id PK, username VARCHAR(50) UNIQUE, password_hash VARCHAR(255), created_at DATETIME)`
- `settings(`key` VARCHAR(50) PK, `value` TEXT)` — 存放 `schema_version`、`installed`。

约定：
- 属性 `key` 正则 `^[a-z][a-z0-9_]*$`，同一存储库内唯一。
- 属性类型仅三种：`text`（文本）、`date`（日期+时间）、`filesize`（字节整数，界面带单位）。
- 受约束属性单选；未受约束属性为自由输入。
- 删除存储库/属性级联删除其链接、属性值与可选值，WebUI 二次确认。
- 同库内 URL 唯一（按 `url_hash` 判定，命中重复时报错并跳过）。

### [S2.4] 类型化存储与过滤

- `text` → `value_text`；`date` → `value_date`（DATETIME）；`filesize` → `value_num`（字节数）。
- 过滤操作符：文本/受约束属性用精确匹配 `filter[key]=value`；日期与文件大小用 `filter[key_gte]` / `filter[key_lte]`（可选 `_gt` / `_lt`）；`q` 对 url 与所有文本属性做子串匹配。多条件为 AND。
- 排序：`sort=created_at|-created_at|updated_at|url|<attr_key>`；属性排序按类型对对应列排序。

### [S2.5] HTTP API（RESTful，前缀 /v1）

- 鉴权：`Authorization: Bearer <token>`。token 解析后与 `api_keys.key_hash` 比对；校验过期；确定绑定存储库与权限。
- 只提供链接读写 + 只读 schema；不通过 API 管理存储库/属性定义。
- 响应统一：`{"code":0,"message":"ok","data":...}`，成功 HTTP 200/201；错误用非 0 code 且配适当 HTTP 状态码（400 参数、401 未认证、403 越权/只读、404 不存在、409 冲突、422 校验失败、500 服务器错误）。
- 端点：
  - `GET /v1/schema` → 存储库信息 + 属性列表（含 key/label/type/required/constrained/options）。
  - `GET /v1/links?page=&page_size=&sort=&q=&filter[key]=&filter[key_gte]=...` → 分页链接列表（含值）。
  - `POST /v1/links` → body 为对象=新增单条；body 为数组=批量新增，返回逐条结果明细。
  - `GET /v1/links/{id}`、`PATCH /v1/links/{id}`（局部更新，未传属性保持；传 `null` 清除）、`DELETE /v1/links/{id}`。
- 只读 KEY 对写方法返回 403。
- 校验：`url` 必须为合法 http/https；受约束属性值必须属于选项；必填属性必须提供；类型必须匹配。

### [S2.6] WebUI

- 认证：单管理员账号（安装向导设置，`password_hash` 存储）+ 会话；所有修改请求带 CSRF token。
- 页面：
  - 首页：存储库网格（卡片），展示名称、描述、链接数、操作入口。
  - 存储库详情：链接列表（宽屏表格 / 窄屏卡片切换）、属性生成过滤控件 + 关键词搜索、分页（含预置每页条数 10/20/50/100，服务端白名单校验，切换后回到第 1 页）、多选。
  - 属性管理：增删改属性、维护可选值列表、配置必填与约束。
  - APIKEY 管理：创建（明文仅显示一次）、列表、吊销、过期时间、名称备注。
  - 链接单条新增/编辑弹窗；CSV 批量导入（粘贴或上传，含前 N 行预览）；批量删除；批量修改某属性值；CSV 导出全部或选中行。
- 适配：手写 CSS + media query，宽屏表格↔窄屏卡片；深色模式支持跟随系统与手动切换（localStorage 记忆）。
- 语言：简体中文。

### [S2.7] 安装向导

首次访问（无 `config.php` 或未安装）进入向导：填写 DB 主机/库名/账号/密码/端口 → 测试连接 → 执行 `schema.sql` 建表 → 设置管理员账号密码 → 生成 `config.php`（含 app_secret）与 `installed` 标记。已安装后访问向导重定向到登录。

### [S2.8] 安全边界

- 全部 SQL 走 PDO 预处理，禁止字符串拼接。
- 输出统一 HTML 转义防 XSS。
- 管理端 POST 全部校验 CSRF。
- API token 存 sha256 哈希；管理员密码走 `password_hash`/`password_verify`。
- url 仅允许 http/https，避免 `javascript:` 等。
- `config.php` 与源码目录禁止直接访问。

### [S2.9] 测试边界

- 单元级：类型校验、过滤构造、CSV 解析、url 规范化（可脚本化断言）。
- 集成级：通过本地 PHP 内置服务器 + MySQL 5.6 起服务，跑 HTTP 冒烟脚本验证安装向导、登录、CRUD、批量、过滤、CSV 导入导出、API 鉴权与越权、只读 KEY 拒绝写入。
- 无 PHPUnit 依赖，使用自带的纯 PHP 测试脚本 + HTTP 冒烟脚本。

## [S3] Out of Scope

- 多用户、角色与权限体系；仅单管理员。
- 通过 API 管理存储库/属性定义。
- 链接可用性探测/去重爬取、定时任务、统计报表。
- 文件本身的上传与下载代理；系统只管理链接。
- 国际化多语言；界面仅简体中文。
- 云存储/对象存储对接。
- Composer/框架/前端构建工具链。

## Tasks

- [x] T1：项目骨架、.htaccess、config 生成、bootstrap、路由与 PDO 封装 — 验收：访问空配置重定向安装，访问已安装进入登录。 (covers: S2.2, S2.7)
- [x] T2：schema.sql + 安装向导（连接测试、建表、建管理员、写 config） — 验收：向导跑完能登录。 (covers: S2.3, S2.7; depends: T1)
- [x] T3：管理员会话认证 + CSRF + 登录页 — 验收：未登录访问管理页被重定向，登录后可访问。 (covers: S2.6, S2.8; depends: T2)
- [x] T4：模型层 Repository/Attribute/Option/Link/LinkValue/ApiKey/AdminUser/Setting（含 EAV 写入与类型化读取、url 唯一） — 验收：脚本调用模型能完成 CRUD 且类型正确。 (covers: S2.3, S2.4; depends: T2)
- [x] T5：存储库网格首页 + 存储库 CRUD — 验收：可增删改存储库并级联。 (covers: S2.6; depends: T3, T4)
- [x] T6：属性管理（类型/必填/约束/可选值/排序） — 验收：受约束属性仅能选列表值。 (covers: S2.3, S2.6; depends: T4)
- [x] T7：链接列表 + 过滤控件 + 关键词 + 分页排序 + 宽窄屏切换 — 验收：按属性过滤结果正确，窄屏为卡片。 (covers: S2.4, S2.6; depends: T4, T6)
- [x] T8：链接单条新增/编辑/删除 + 批量删除 + 批量改属性 — 验收：操作生效并可撤销式确认。 (covers: S2.6; depends: T7)
- [x] T9：CSV 导入（粘贴/上传 + 前 N 行预览）+ CSV 导出（全部/选中） — 验收：表头列名=属性 key，导入预览准确，导出可再导入。 (covers: S2.6; depends: T8)
- [x] T10：API 鉴权（Bearer + 哈希 + 过期 + 只读/读写）+ schema 接口 — 验收：无/错 token 401、只读 KEY 写返回 403、越库不可见。 (covers: S2.5, S2.8; depends: T4)
- [x] T11：API 链接 CRUD + 批量新增 + 过滤/分页/排序 + 错误码 — 验收：API 契约与响应格式一致。 (covers: S2.5; depends: T10)
- [x] T12：APIKEY 管理界面（创建/列表/吊销/过期/备注） — 验收：创建仅显示一次明文，吊销后失效。 (covers: S2.6; depends: T3, T4)
- [x] T13：CSS/JS：响应式布局、深色模式、过滤控件、弹窗、多选交互 — 验收：桌面与移动端均可用，深浅色可切换。 (covers: S2.6; depends: T7)
- [x] T14：验证：本地 PHP8.5 + MySQL5.6 起服务，跑单元脚本与 HTTP 冒烟脚本 — 验收：全部通过并记录命令与结果。 (covers: S2.9; depends: T1..T13)
- [x] T15：链接列表每页条数切换（预置 10/20/50/100，服务端白名单，切换重置到第 1 页，保留过滤/搜索/排序参数） — 验收：切换后条数生效、选中项正确、非法值回落 20。 (covers: S2.6; depends: T7)
