# HTTP API 参考

下载链接管理系统（DLM）对外提供的 RESTful HTTP API。用于脚本化地读写某个存储库下的下载链接。

- **Base URL**：`https://<你的域名>`（若部署在子目录则为 `https://<域名>/<子目录>`）
- **前缀**：`/v1`
- **编码**：请求与响应均为 UTF-8 JSON
- **鉴权**：`Authorization: Bearer <APIKEY>`

---

## 目录

- [鉴权](#鉴权)
- [响应格式](#响应格式)
- [状态码与错误码](#状态码与错误码)
- [数据类型](#数据类型)
- [端点总览](#端点总览)
- [GET /v1/schema](#get-v1schema)
- [GET /v1/links](#get-v1links)
- [POST /v1/links](#post-v1links)
- [GET /v1/links/{id}](#get-v1linksid)
- [PATCH /v1/links/{id}](#patch-v1linksid)
- [DELETE /v1/links/{id}](#delete-v1linksid)
- [过滤、排序与分页详解](#过滤排序与分页详解)
- [完整调用示例](#完整调用示例)
- [限制与注意事项](#限制与注意事项)

---

## 鉴权

### 获取 APIKEY

登录 WebUI → 顶部导航「APIKEY」→ 选择存储库、权限（只读 / 读写）、可选名称与过期时间 → 创建。

创建成功后**明文只显示一次**（格式 `dlm_` + 40 位十六进制），请立即保存。服务端只保存 `sha256` 哈希与前 12 位前缀。

### 使用

```http
GET /v1/links HTTP/1.1
Host: dlm.example.com
Authorization: Bearer dlm_3f8a1c9e5b7d2f4a6c8e0b1d3f5a7c9e2b4d6f8a
```

### 鉴权规则

| 规则 | 说明 |
| --- | --- |
| 绑定存储库 | 每个 KEY 只绑定一个存储库，**所有**接口都隐式限定在该库范围内；其他库的资源一律不可见（返回 404） |
| 权限档位 | `read`：仅可调用 `GET`；`write`：可调用全部方法 |
| 过期 | `expires_at` 为 `DATETIME`，到期后该 KEY 视为无效（401） |
| 吊销 | 在 WebUI 删除 KEY 后立即失效（401） |
| 只读写操作 | 使用 `read` 权限 KEY 调用 `POST` / `PATCH` / `DELETE` → **403**，`code=4030` |

---

## 响应格式

**所有**响应（成功与失败）都遵循同一结构：

```json
{
  "code": 0,
  "message": "ok",
  "data": {}
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `code` | int | `0` 表示成功；非 0 为业务错误码 |
| `message` | string | 人类可读的提示（中文） |
| `data` | mixed | 成功时为业务数据；失败时通常为 `null` |

### 状态码与错误码

| HTTP | `code` | 含义 | 触发场景 |
| --- | --- | --- | --- |
| 200 | 0 | 成功 | 查询、更新、删除、批量创建 |
| 201 | 0 | 创建成功 | 单条创建（`POST` 请求体为对象） |
| 400 | 4000 | 请求参数错误 | 请求体不是合法 JSON 对象/数组 |
| 401 | 4010 | 未认证 / 凭证无效 | 缺少 token、token 不存在、token 已过期 |
| 403 | 4030 | 无权限 | 只读 KEY 执行写操作 |
| 404 | 4040 | 资源不存在 | 链接 ID 不存在或不属于当前库；接口路径不存在 |
| 422 | 4220 | 校验失败 | URL 非法、必填缺失、取值不在选项内、日期/大小格式错误、URL 重复、未知属性 |
| 500 | 5000 | 服务器内部错误 | 未捕获异常（详见 `logs/php-error.log`） |
| 503 | 5030 | 服务不可用 | 系统尚未完成安装 |

> `code` 与 HTTP 状态码一一对应，业务判断请以 `code` 为准。

错误响应示例：

```json
{
  "code": 4220,
  "message": "版本号为必填项；下载 URL 无效，请检查 http/https 地址格式",
  "data": null
}
```

多条校验错误会以中文分号 `；` 拼接在 `message` 中。

---

## 数据类型

### Attribute（属性定义）

```json
{
  "key": "channel",
  "label": "渠道",
  "type": "text",
  "required": true,
  "constrained": true,
  "options": ["稳定版", "测试版"]
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `key` | string | 机器名，`^[a-z][a-z0-9_-]*$`，同库唯一；用于 `attributes` 对象的键名与 `filter[...]` 的参数名 |
| `label` | string | 中文显示名 |
| `type` | string | `text` \| `date` \| `filesize` |
| `required` | bool | 创建链接时是否必须提供 |
| `constrained` | bool | 是否只允许取 `options` 中的值（仅 `text` 支持） |
| `options` | string[] | 可选值列表；`constrained=false` 时为空数组 |

### Link（链接对象）

```json
{
  "id": 42,
  "url": "https://example.com/app-1.0.zip",
  "created_at": "2026-10-17 10:12:33",
  "updated_at": "2026-10-17 10:12:33",
  "attributes": {
    "channel": "稳定版",
    "version": "1.0.0",
    "release_date": "2026-01-01 12:00:00",
    "size": 104857600
  }
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int | 链接 ID（在所属存储库内唯一） |
| `url` | string | 下载地址，`http` / `https` / `ed2k` / `magnet`，同库唯一 |
| `created_at` | string | `YYYY-MM-DD HH:MM:SS`（服务器时区，默认 `Asia/Shanghai`） |
| `updated_at` | string | 同上 |
| `attributes` | object | 以属性 `key` 为键的取值映射；**该库的所有属性都会出现**，未设置值为 `null` |

`attributes` 各值的类型取决于属性类型：

| 属性类型 | JSON 类型 | 示例 | 说明 |
| --- | --- | --- | --- |
| `text` | string \| null | `"1.0.0"` | 原样返回 |
| `date` | string \| null | `"2026-01-01 12:00:00"` | 返回 `YYYY-MM-DD HH:MM:SS`；输入时可省略秒 |
| `filesize` | int \| null | `104857600` | **始终返回字节数**（整数），不返回 `"100MB"` 形式 |

> 输入与输出不对称：`filesize` 写入时可用 `"100MB"`、`"1.5GB"` 或字节数，读出时统一为字节整数；`date` 写入时可用 `YYYY-MM-DD` / `YYYY-MM-DD HH:MM` / 带秒，读出时统一带秒。
>
> **日期分隔符必须是空格**：API **不接受** ISO 8601 的 `T` 分隔写法（`2026-01-01T12:00` 会被判为格式错误返回 422）。WebUI 的 `datetime-local` 控件会由服务端自动把 `T` 转成空格，API 调用方需自行使用 `2026-01-01 12:00`。

---

## 端点总览

| 方法 | 路径 | 权限 | 说明 |
| --- | --- | --- | --- |
| GET | `/v1/schema` | read / write | 获取存储库信息与属性定义 |
| GET | `/v1/links` | read / write | 分页查询链接列表 |
| POST | `/v1/links` | **write** | 创建链接（对象=单条，数组=批量） |
| GET | `/v1/links/{id}` | read / write | 获取单条链接 |
| PATCH | `/v1/links/{id}` | **write** | 局部更新链接 |
| DELETE | `/v1/links/{id}` | **write** | 删除链接 |

---

## GET /v1/schema

返回当前 KEY 所绑定存储库的元信息与全部属性定义。用于脚本动态发现字段。

### 请求

```http
GET /v1/schema
Authorization: Bearer <key>
```

无参数。

### 响应 `200`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "repository": {
      "id": 3,
      "name": "软件下载",
      "slug": "software"
    },
    "attributes": [
      {
        "key": "version",
        "label": "版本号",
        "type": "text",
        "required": true,
        "constrained": false,
        "options": []
      },
      {
        "key": "channel",
        "label": "渠道",
        "type": "text",
        "required": true,
        "constrained": true,
        "options": ["稳定版", "测试版"]
      },
      {
        "key": "release_date",
        "label": "发布时间",
        "type": "date",
        "required": false,
        "constrained": false,
        "options": []
      },
      {
        "key": "size",
        "label": "文件大小",
        "type": "filesize",
        "required": false,
        "constrained": false,
        "options": []
      }
    ]
  }
}
```

`attributes` 按 `sort_order`、`id` 升序排列。

---

## GET /v1/links

分页查询链接列表，支持属性过滤、关键词搜索与排序。

### 请求

```http
GET /v1/links?page=1&page_size=20&sort=-created_at&q=beta&filter[channel]=稳定版&filter[size_gte]=100MB
Authorization: Bearer <key>
```

### 查询参数

| 参数 | 类型 | 默认 | 说明 |
| --- | --- | --- | --- |
| `page` | int | `1` | 页码，最小 1 |
| `page_size` | int | `20` | 每页条数，范围 **1–200**（超出自动夹取） |
| `sort` | string | `-created_at` | 排序字段，见[排序](#排序) |
| `q` | string | 空 | 关键词，对 `url` 与所有文本属性值做子串匹配 |
| `filter[<key>]` | string | — | 属性精确匹配 |
| `filter[<key>_contains]` | string | — | 属性子串匹配（`LIKE %v%`） |
| `filter[<key>_gte]` | string | — | `>=` |
| `filter[<key>_lte]` | string | — | `<=` |
| `filter[<key>_gt]` | string | — | `>` |
| `filter[<key>_lt]` | string | — | `<` |

> 多个 `filter` 之间为 **AND** 关系。`filter` 的键名必须是该库已存在的属性 `key`，否则返回 **422**（`未知属性：xxx`）。

### 响应 `200`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "items": [
      {
        "id": 42,
        "url": "https://example.com/app-1.0.zip",
        "created_at": "2026-10-17 10:12:33",
        "updated_at": "2026-10-17 10:12:33",
        "attributes": {
          "version": "1.0.0",
          "channel": "稳定版",
          "release_date": "2026-01-01 12:00:00",
          "size": 104857600
        }
      }
    ],
    "total": 137,
    "page": 1,
    "page_size": 20
  }
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `items` | Link[] | 当前页数据 |
| `total` | int | 符合条件的总条数（用于计算总页数） |
| `page` | int | 当前页码（已应用最小值 1） |
| `page_size` | int | 实际生效的每页条数（已夹取到 1–200） |

### 示例

```bash
# 按渠道精确过滤 + 大小区间 + 关键词
curl -s "https://dlm.example.com/v1/links?filter%5Bchannel%5D=%E7%A8%B3%E5%AE%9A%E7%89%88&filter%5Bsize_gte%5D=100MB&q=1.0" \
  -H "Authorization: Bearer $DLM_TOKEN"
```

---

## POST /v1/links

创建链接。请求体为 **JSON 对象**时创建单条，为 **JSON 数组**时批量创建。

需要 `write` 权限。

### 单条创建

#### 请求

```http
POST /v1/links
Authorization: Bearer <key>
Content-Type: application/json
```

```json
{
  "url": "https://example.com/app-1.0.zip",
  "attributes": {
    "version": "1.0.0",
    "channel": "稳定版",
    "release_date": "2026-01-01 12:00",
    "size": "100MB"
  }
}
```

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `url` | string | 是 | `http` / `https` / `ed2k` / `magnet`，同库唯一 |
| `attributes` | object | 否* | 以属性 `key` 为键的取值；`required=true` 的属性必须提供 |

\* 若该库存在必填属性，则 `attributes` 必须包含它们。

#### 响应 `201`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 43,
    "url": "https://example.com/app-1.0.zip",
    "created_at": "2026-10-17 11:05:20",
    "updated_at": "2026-10-17 11:05:20",
    "attributes": {
      "version": "1.0.0",
      "channel": "稳定版",
      "release_date": "2026-01-01 12:00:00",
      "size": 104857600
    }
  }
}
```

#### 错误

| HTTP / code | 场景 |
| --- | --- |
| 422 / 4220 | `url` 非法；必填属性缺失；取值不在 `options` 内；日期或大小格式错误；`未知属性：xxx`；同库 URL 重复（`该存储库中已存在相同的下载 URL`） |
| 403 / 4030 | 使用只读 KEY |
| 400 / 4000 | 请求体不是合法 JSON |

### 批量创建

请求体为 JSON 数组，每个元素结构与单条创建相同。**逐条独立处理**，部分失败不影响其他条。

#### 请求

```json
[
  {
    "url": "https://example.com/app-1.0.zip",
    "attributes": { "version": "1.0.0", "channel": "稳定版" }
  },
  {
    "url": "not-a-valid-url",
    "attributes": {}
  }
]
```

#### 响应 `200`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "created": 1,
    "failed": 1,
    "results": [
      {
        "index": 0,
        "ok": true,
        "id": 44,
        "url": "https://example.com/app-1.0.zip",
        "errors": []
      },
      {
        "index": 1,
        "ok": false,
        "url": "not-a-valid-url",
        "errors": ["下载 URL 无效，必须以 http://、https://、ed2k:// 或 magnet:? 开头"]
      }
    ]
  }
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `created` | int | 成功创建条数 |
| `failed` | int | 失败条数 |
| `results` | object[] | 逐条结果，顺序与请求数组一致 |
| `results[].index` | int | 对应请求数组下标（从 0 开始） |
| `results[].ok` | bool | 是否成功 |
| `results[].id` | int | 成功时返回新链接 ID |
| `results[].url` | string | 该条请求的 URL（原样回显） |
| `results[].errors` | string[] | 失败原因；成功时为空数组 |

> 批量创建**始终返回 200**（即使全部失败），请检查 `created` / `failed` 与逐条 `ok`。
> 数组中若含非对象元素（如字符串、数字），该条记为失败，`errors` 为 `["请求项必须是对象"]`。

---

## GET /v1/links/{id}

获取单条链接。

### 请求

```http
GET /v1/links/42
Authorization: Bearer <key>
```

| 参数 | 位置 | 说明 |
| --- | --- | --- |
| `id` | path | 链接 ID（整数） |

### 响应 `200`

返回与列表 `items[]` 中相同结构的 Link 对象：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 42,
    "url": "https://example.com/app-1.0.zip",
    "created_at": "2026-10-17 10:12:33",
    "updated_at": "2026-10-17 10:12:33",
    "attributes": {
      "version": "1.0.0",
      "channel": "稳定版",
      "release_date": "2026-01-01 12:00:00",
      "size": 104857600
    }
  }
}
```

### 错误

| HTTP / code | 场景 |
| --- | --- |
| 404 / 4040 | ID 不存在，或该链接属于其他存储库（`链接不存在`） |

---

## PATCH /v1/links/{id}

局部更新。**未出现在请求体中的字段保持不变**；显式传 `null` 或空串表示清除该属性值。

需要 `write` 权限。

### 请求

```http
PATCH /v1/links/42
Authorization: Bearer <key>
Content-Type: application/json
```

```json
{
  "attributes": {
    "version": "1.0.1",
    "size": null
  }
}
```

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `url` | string | 否 | 不传则保持原值；传了则必须是合法 http/https/ed2k/magnet 链接且同库不重复 |
| `attributes` | object | 否 | 只更新出现的键；值为 `null` 或空串表示**删除**该属性值 |

> **必填属性在更新时的行为**：`required=true` 的属性若**不出现在** `attributes` 中，则跳过校验、保持原值；若**显式传 `null` 或空串**，会返回 **422**（`<label>为必填项`）。因此必填属性只能改成另一个合法值，不能清空。

### 响应 `200`

返回更新后的完整 Link 对象（结构同 `GET /v1/links/{id}`）。

### 错误

| HTTP / code | 场景 |
| --- | --- |
| 404 / 4040 | ID 不存在或不属于当前库 |
| 422 / 4220 | 新 `url` 非法或与其他链接重复；属性值校验失败；`未知属性：xxx` |
| 403 / 4030 | 使用只读 KEY |

> 注意：`PATCH` 与 `PUT` 不同——本接口是**合并语义**，不是整体替换。

---

## DELETE /v1/links/{id}

删除单条链接。其所有属性值随外键级联删除。

需要 `write` 权限。

### 请求

```http
DELETE /v1/links/42
Authorization: Bearer <key>
```

### 响应 `200`

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

### 错误

| HTTP / code | 场景 |
| --- | --- |
| 404 / 4040 | ID 不存在或不属于当前库 |
| 403 / 4030 | 使用只读 KEY |

> 删除操作**幂等性说明**：重复删除同一 ID 时，第二次返回 404。

---

## 过滤、排序与分页详解

### 过滤

`filter` 是嵌套查询参数，形式为 `filter[<key><op>] = <value>`。

**操作符后缀**

| 后缀 | 运算 | 说明 |
| --- | --- | --- |
| 无 | `=` 精确匹配 | 全部类型 |
| `_contains` | `LIKE '%v%'` | 主要用于文本子串。用在 `date` / `filesize` 属性上时，值会先按该类型解析再对对应列做 `LIKE`，语义不可靠，不建议使用 |
| `_gte` | `>=` | 数值/日期区间下界 |
| `_lte` | `<=` | 数值/日期区间上界 |
| `_gt` | `>` | 同上，不含边界 |
| `_lt` | `<` | 同上，不含边界 |

**值的解析规则**

| 属性类型 | 过滤值写法 | 服务端处理 |
| --- | --- | --- |
| `text` | 任意字符串 | 直接比较 / 子串匹配 |
| `date` | `2026-01-01`、`2026-01-01 12:00`、`2026-01-01 12:00:00` | 统一补全为 `YYYY-MM-DD HH:MM:SS` 后按 DATETIME 比较；格式非法则**该条件被忽略** |
| `filesize` | `1024`、`100MB`、`1.5GB` | 解析为字节数后按 BIGINT 比较；无法解析则**该条件被忽略** |

**示例**

```
# 渠道 = 稳定版
filter[channel]=稳定版

# 版本号包含 beta
filter[version_contains]=beta

# 发布时间在 2026 年内
filter[release_date_gte]=2026-01-01&filter[release_date_lte]=2026-12-31

# 文件大小 100MB ~ 5GB
filter[size_gte]=100MB&filter[size_lte]=5GB
```

> 与 WebUI 的差异：WebUI 会**静默忽略**未知属性，API 则返回 **422**（`未知属性：xxx`），便于脚本尽早发现字段拼写错误。

### 排序

`sort=<field>`，前缀 `-` 表示倒序（`DESC`），无前缀为升序（`ASC`）。

| `sort` 取值 | 排序依据 |
| --- | --- |
| `created_at` / `-created_at` | 创建时间（默认 `-created_at`） |
| `updated_at` / `-updated_at` | 更新时间 |
| `url` / `-url` | URL 字符串 |
| `<attr_key>` / `-<attr_key>` | 该属性对应的存储列（`text`→`value_text`，`date`→`value_date`，`filesize`→`value_num`） |

无法识别的 `sort` 值会**回退**为 `created_at DESC`（不报错）。空值属性在升序时排在最前，倒序时排在最后（MySQL 默认行为）。所有排序都会附加 `id DESC` 作为稳定的次级排序。

### 分页

```
GET /v1/links?page=3&page_size=50
```

- `page` 从 1 开始，小于 1 会被修正为 1
- `page_size` 会被夹取到 `[1, 200]`
- 返回体中的 `page` / `page_size` 是**实际生效值**，请以此为准
- 总页数 = `ceil(total / page_size)`

---

## 完整调用示例

### cURL（Bash）

```bash
export DLM_TOKEN="dlm_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
export BASE="https://dlm.example.com"

# 1) 查看 schema，确认属性 key
curl -s "$BASE/v1/schema" -H "Authorization: Bearer $DLM_TOKEN" | jq .

# 2) 创建一条链接
curl -s -X POST "$BASE/v1/links" \
  -H "Authorization: Bearer $DLM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
        "url": "https://example.com/app-2.0.zip",
        "attributes": {
          "version": "2.0.0",
          "channel": "稳定版",
          "release_date": "2026-03-01 08:00",
          "size": "256MB"
        }
      }' | jq .

# 3) 批量创建
curl -s -X POST "$BASE/v1/links" \
  -H "Authorization: Bearer $DLM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '[
        {"url":"https://example.com/a.zip","attributes":{"version":"1.0","channel":"稳定版"}},
        {"url":"https://example.com/b.zip","attributes":{"version":"1.1","channel":"测试版"}}
      ]' | jq '.data | {created, failed}'

# 4) 分页 + 过滤 + 排序
curl -s -G "$BASE/v1/links" \
  --data-urlencode "filter[channel]=稳定版" \
  --data-urlencode "filter[size_gte]=100MB" \
  --data-urlencode "sort=-release_date" \
  --data-urlencode "page=1" \
  --data-urlencode "page_size=20" \
  -H "Authorization: Bearer $DLM_TOKEN" | jq '.data.items[] | {id, url}'

# 5) 局部更新（清除 size）
curl -s -X PATCH "$BASE/v1/links/42" \
  -H "Authorization: Bearer $DLM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"attributes":{"size":null}}' | jq .

# 6) 删除
curl -s -X DELETE "$BASE/v1/links/42" -H "Authorization: Bearer $DLM_TOKEN" | jq .
```

### PowerShell

```powershell
$Token = 'dlm_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
$Base  = 'https://dlm.example.com'
$H = @{ Authorization = "Bearer $Token" }

# schema
Invoke-RestMethod -Uri "$Base/v1/schema" -Headers $H | ConvertTo-Json -Depth 8

# 创建
$body = @{
    url        = 'https://example.com/app-2.0.zip'
    attributes = @{ version = '2.0.0'; channel = '稳定版'; size = '256MB' }
} | ConvertTo-Json -Depth 5
Invoke-RestMethod -Uri "$Base/v1/links" -Method Post -Headers $H `
    -ContentType 'application/json' -Body $body | ConvertTo-Json -Depth 8

# 列表（注意：filter 键名含方括号，需自行拼接 URL）
$q = 'filter%5Bchannel%5D=' + [uri]::EscapeDataString('稳定版') + '&page=1&page_size=20'
Invoke-RestMethod -Uri "$Base/v1/links?$q" -Headers $H | ConvertTo-Json -Depth 8
```

### Python

```python
import requests

BASE = "https://dlm.example.com"
HEADERS = {"Authorization": "Bearer dlm_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"}

# schema
schema = requests.get(f"{BASE}/v1/schema", headers=HEADERS).json()
print([a["key"] for a in schema["data"]["attributes"]])

# 创建
resp = requests.post(f"{BASE}/v1/links", headers=HEADERS, json={
    "url": "https://example.com/app-2.0.zip",
    "attributes": {"version": "2.0.0", "channel": "稳定版", "size": "256MB"},
})
data = resp.json()
assert data["code"] == 0, data["message"]
print("created id =", data["data"]["id"])

# 列表（requests 会自动编码嵌套参数）
resp = requests.get(f"{BASE}/v1/links", headers=HEADERS, params={
    "filter[channel]": "稳定版",
    "filter[size_gte]": "100MB",
    "sort": "-release_date",
    "page": 1,
    "page_size": 20,
})
for item in resp.json()["data"]["items"]:
    print(item["id"], item["url"], item["attributes"]["size"])
```

### 同步脚本骨架（幂等写入）

```python
import requests

BASE = "https://dlm.example.com"
HEADERS = {"Authorization": "Bearer dlm_xxx"}
TARGET_URL = "https://example.com/app-2.0.zip"
attrs = {"version": "2.0.0", "channel": "稳定版"}

# 1) 用 q 做子串搜索（url 是固定字段，不能用 filter[url]），再在客户端比对完整 URL
items = requests.get(f"{BASE}/v1/links", headers=HEADERS, params={
    "q": TARGET_URL,
    "page_size": 200,
}).json()["data"]["items"]

matched = next((it for it in items if it["url"] == TARGET_URL), None)

# 2) 存在则 PATCH，不存在则 POST
if matched:
    r = requests.patch(f"{BASE}/v1/links/{matched['id']}",
                       headers=HEADERS, json={"attributes": attrs})
else:
    r = requests.post(f"{BASE}/v1/links", headers=HEADERS,
                      json={"url": TARGET_URL, "attributes": attrs})

body = r.json()
print(r.status_code, body["code"], body["message"])
```

> `url` 是固定字段而非属性，因此**不能**用 `filter[url]=...` 精确过滤（会返回 422 `未知属性：url`）。请用 `q` 做子串搜索，再在客户端比对完整 URL；或直接把重复创建交给服务端，用 422 的 `message` 判断（含「已存在」）。

---

## 限制与注意事项

- **不支持通过 API 管理存储库与属性定义**，这些只能在 WebUI 中操作。脚本可先用 `GET /v1/schema` 读取属性结构。
- **不支持跨库操作**：KEY 与存储库一对一绑定，无法通过参数切换库。
- **没有单条更新的 `PUT`**，只有合并语义的 `PATCH`。
- **没有批量删除 / 批量更新接口**；批量仅支持创建。
- **`page_size` 上限 200**；需要导出全量请循环分页（`total` 字段可用于计算页数）。
- **`url` 唯一性按规范化结果判定**（http/https：scheme/host 小写；ed2k：整串小写；magnet：参数排序且 `xt` 哈希小写，之后取 sha256），`HTTP://Example.COM/a` 与 `http://example.com/a` 视为重复，`magnet:?xt=urn:btih:ABC&dn=a` 与 `magnet:?dn=a&xt=urn:btih:abc` 同样视为重复。
- **`attributes` 中出现的未知 `key` 会导致整条请求 422 失败**，不会静默忽略。
- **日期无时区概念**：按服务器配置的时区（默认 `Asia/Shanghai`）存储与返回 `DATETIME`。
- **API 请求的 404/500 返回 JSON**；页面请求返回 HTML。可通过路径前缀 `/v1` 区分。
- **不要在前端（浏览器）直接使用 APIKEY**：它是全库读写凭证，应仅在服务端脚本中使用。
