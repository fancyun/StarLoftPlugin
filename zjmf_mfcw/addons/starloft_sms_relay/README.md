# StarLoft 短信中转插件（智简魔方财务版）

把 **StarLoft（星楼网络）** 的短信 API 转售给站点自有客户的渠道插件。站点管理员为每个客户生成一对**独立中转密钥**，客户持密钥调用本站点的中转端点；插件用站点配置的平台密钥转发到 StarLoft，并原样回传响应、按客户记录调用日志。

- 插件类型：`addons`（智简魔方官方「插件」分类：插件置于 `public/plugins/addons/`，命名空间 `addons\{目录名}`，外部访问前缀 `/addons/{目录名}/`）
- 插件标识（目录名）：`starloft_sms_relay`
- 只中转 `sms/*` 端点；人脸核验请另装 `starloft_fv_relay`
- 依赖：PHP >= 7.0、curl / json 扩展、智简魔方财务版 3.7.6+

## 1. 安装

1. 将 `starloft_sms_relay` 目录上传到 `/public/plugins/addons/starloft_sms_relay/`（**目录名必须与命名空间/类名一致**）。
2. 后台进入插件管理，找到「StarLoft 短信中转」并点击「安装」。
   - 安装会尝试创建两张表（客户密钥表、调用日志表），表名带站点数据库前缀。**建表失败不影响安装**：中转仍可用，只是密钥管理页会提示，请把页面上给出的 SQL 交给 DBA 手工执行。
3. 点击「配置」，填写平台 API 地址 / API Key / API Secret 与**管理令牌**。

## 2. 配置项

| 配置项 | 必填 | 说明 |
|--------|------|------|
| 平台API地址 | ✅ | 星楼网络平台 API 地址，默认 `https://api.starloft.cn` |
| 平台API Key | ✅ | 本站点在星楼网络的 API 密钥；**客户调用消耗的是这个账号的余额/资源包** |
| 平台API Secret | ✅ | 与 Key 配对，用于 HMAC 签名 |
| 管理令牌 | - | 管理页与所有管理操作的通行口令；留空时管理页不可访问 |
| 端点白名单 | - | 留空=全部端点可中转；多个用英文逗号分隔，如 `sms/send,sms/report` |
| 单客户日调用上限 | - | 每个客户密钥每天最多调用次数，`0`=不限；超限返回 429 |
| 短信发送单价 | - | 按请求 `phone_number_set` 里的号码个数计费 |
| 其余端点单价 | - | 签名/模板/回执/上行等端点每次调用扣费金额，`0`=免费 |
| 记录调用日志 | - | 是否记录调用明细（客户 / 端点 / 结果 / 耗时 / IP），供站点给客户对账 |

> 平台侧前置要求：**短信端点要求平台账号完成个人实名**，且该 API 密钥的权限需为 `all` 或包含对应端点标识，否则平台会返回 `code:403`。

### 计费（客户预付余额，必须先充值才能调用）

插件给每个客户密钥带一份**独立预付余额**（`starloft_sms_relay_key.balance`，不复用魔方核心余额表），在管理页为客户充值/扣减。计费规则：

| 配置项 | 计价方式 |
|---|---|
| 短信发送单价 | 按请求 `phone_number_set` 里的**号码个数**计费（长短信拆分条数不另计，请按最坏情况定价） |
| 其余端点单价 | 签名/模板/回执/上行等端点每次调用扣一次 |

调用流程：**验签通过 → 按上述规则预扣（余额不足直接返回 402，不发起平台调用）→ 转发到平台 → 平台返回 `code != 0`（含转发失败）时把预扣金额原路退还**。响应头会带 `X-Relay-Price`（本次扣费）、`X-Relay-Balance`（扣后余额）、`X-Relay-Units`（计费单位数），客户可据此对账；每次调用的扣费与余额也记在调用日志里。单价留 `0` 即该端点免费。

## 3. 管理页（后台插件菜单，或带令牌的免登录地址）

后台入口：**插件 → StarLoft 短信中转 → 管理控制台**（管理员登录由魔方保证，不需要令牌）。

免登录入口（需带管理令牌）：

```
https://你的站点/addons/starloft_sms_relay/apiAdmin?token=你配置的管理令牌
```

页面提供：

- **新建客户密钥**：填客户名称、权限（`all` 或逗号分隔权限码）、备注 → 生成一对密钥。
  - `access_key` 形如 `sk_2f9c...`（列表中以掩码显示）
  - `access_secret` **仅在创建/重置的那一次显示**，请立即交给客户，丢失只能重置
- **余额**：列表里每个客户一行余额，右侧「金额 + 充值/扣减」按钮直接加减（客户调用必须余额充足）
- **停用 / 启用 / 重置密钥 / 删除**：客户欠费或密钥泄露时停用或重置
- **测试平台连通/鉴权**：用平台密钥探活，返回「平台连通且鉴权通过」即配置正确
- **最近调用日志**：客户、端点、HTTP 状态、业务码、扣费金额、扣后余额、耗时、IP、消息
- **密钥详情 / 修改**：列表里点「详情 / 修改」可查看完整 `access_key` 与 `access_secret`，并直接改客户名称、权限、备注、密钥本身
- **页内导航**：概览（配置状态、计费单价、统计、平台连通测试）/ 密钥管理 / 调用日志 / 使用说明，右上角可一键回到插件列表或后台首页

> 密钥表等同凭据库（Secret 明文存储才能校验签名），请限制数据库访问权限；不要把密钥写入插件目录文件（插件升级会覆盖）。

## 4. 客户接入

### 4.1 请求契约（与平台一致）

| 项 | 值 |
|----|----|
| 请求地址 | `https://你的站点/addons/starloft_sms_relay/apiRelay?endpoint=<端点>` |
| `X-Api-Key` | 客户的中转密钥（`sk_...`） |
| `X-Sign` | `HMAC-SHA256(中转Secret, 原始请求体)` 的小写十六进制；GET/DELETE 无请求体时签空串 |
| `X-Sign-Version` | 固定 `hmac_sha256` |
| `X-Timestamp` | Unix 秒，与服务器时间相差不得超过 300 秒 |
| 请求体 | 与平台同端点的 JSON 入参**完全一致**（插件原样转发） |
| 响应 | 原样回传平台响应与 HTTP 状态码（`{code,message,data}`） |
| 响应头 | `X-Relay-Price` 本次扣费、`X-Relay-Balance` 扣后余额、`X-Relay-Units` 计费单位数 |

端点也可用请求头 `X-Endpoint: sms/send` 指定，此时 URL 不必带 query。

### 4.2 可中转端点

| `endpoint` | HTTP | 平台路径 | 权限码 | 平台实名要求 |
|---|---|---|---|---|
| `sms/send` | POST | `/v1/sms/send` | `sms_send` | 个人 |
| `sms/signs` | POST | `/v1/sms/signs` | `sms_sign` | 个人 |
| `sms/templates` | POST | `/v1/sms/templates` | `sms_template_create` | 个人 |
| `sms/templates/:id` | GET / PUT / DELETE | `/v1/sms/templates/{id}` | `sms_template_get` / `_update` / `_delete` | 个人 |
| `sms/report` | POST | `/v1/sms/report` | `sms_report` | 个人 |
| `sms/replies` | POST | `/v1/sms/replies` | `sms_replies` | 个人 |

带 `:id` 的端点通过 query 传 id：`?endpoint=sms/templates/:id&id=123`。

### 4.3 调用示例

```bash
B='{"phone_number_set":["13800000000"],"template_id":123,"template_params":["123456"]}'
TS=$(date +%s)
SIG=$(printf '%s' "$B" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
curl -sS -X POST "https://你的站点/addons/starloft_sms_relay/apiRelay?endpoint=sms/send" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: $KEY" -H "X-Sign: $SIG" -H "X-Sign-Version: hmac_sha256" -H "X-Timestamp: $TS" \
  -d "$B"
```

```php
$body = json_encode(['biz_no' => '2026...'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sign = hash_hmac('sha256', $body, $secret);
$ch = curl_init('https://你的站点/addons/starloft_sms_relay/apiRelay?endpoint=sms/report');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Api-Key: ' . $key,
        'X-Sign: ' . $sign,
        'X-Sign-Version: hmac_sha256',
        'X-Timestamp: ' . time(),
    ],
]);
$resp = curl_exec($ch);
```

### 4.4 可选：让官方 SDK 直接复用（需站点 Nginx 加一条重写）

官方 SDK 会把 `api_url` 与 `/v1/xxx` 拼在一起，用下面这条重写即可把 `/v1/*` 映射到中转端点，客户无需改 SDK 代码，只改 `api_url` 与密钥：

```nginx
location ^~ /v1/ {
    rewrite ^/v1/(.*)$ /addons/starloft_sms_relay/apiRelay?endpoint=$1 last;
}
```

之后客户把 `api_url` 配成 `https://你的站点`，其余照平台的 API 文档使用即可。

## 5. 错误码（插件返回）

| HTTP | `message` | 含义 |
|---|---|---|
| 401 | `missing required headers` | 四个鉴权头缺失 |
| 400 | `unsupported sign version` | `X-Sign-Version` 不是 `hmac_sha256` |
| 400 | `unknown endpoint（...）` | `endpoint` 缺失或不在白名单 |
| 405 | `method not allowed for this endpoint` | 该端点不支持此 HTTP 方法 |
| 401 | `invalid api key` | 中转密钥不存在 |
| 403 | `api key disabled` | 密钥已停用 |
| 401 | `invalid or expired timestamp` | 时间戳超出 ±300 秒 |
| 401 | `invalid signature` | 签名不匹配（Secret 用错或请求体被改动） |
| 403 | `密钥无权调用该接口（xxx）` | 客户权限不含该端点权限码 |
| 403 | `该端点未开启中转` | 被端点白名单拦截 |
| 429 | `超过当日调用上限（N）` | 命中单客户日上限 |
| 402 | `余额不足，请联系站点管理员充值（当前余额 X 元，本次需 Y 元）` | 客户预付余额不够本次调用（未发起平台调用、未扣费） |
| 500 | `插件未配置平台 API 地址/密钥...` | 站点尚未完成插件配置 |
| 500 | `密钥表不可用：...` | 数据表缺失/无权限，请执行建表 SQL |
| 504 | `转发失败：...` | 连接平台超时/网络异常 |

平台自身的业务错误（如余额不足、模板或签名未通过、实名不足）按平台原样返回，`code` 沿用平台约定。

## 6. 注意事项

- **计费模型**：客户费用走插件自带的**预付余额**（管理页充值/扣减），不经魔方核心余额，也不与魔方商品/订单联动；客户余额不足会直接返回 402。若要让客户在魔方下单后自动开通/充值，需要商品化对接，本插件暂不包含。
- **额度**：客户调用消耗的是站点平台账号的余额/资源包，站点需保证额度充足；平台侧调用失败会自动退款（短信回执失败退费），细则见平台 API 文档。
- **异步回调**：平台会按客户自传的 `notify_url` 直接签名回调，**插件无法代签**，客户必须提供公网可达的回调地址；否则改用 `sms/report` 主动查询结果。
- 中转端点为原始 HTTP 输出（不经过框架页面渲染），出参即平台响应原文。

## 7. 排错

| 现象 | 排查 |
|---|---|
| 访问中转端点 404 | 确认插件目录在 `/public/plugins/addons/starloft_sms_relay/`，且 URL 前缀为 `/addons/starloft_sms_relay/`；确认插件已安装启用 |
| 上一条无误但仍 404 | 该魔方版本的 `addons` 插件不一定把入口方法直接暴露为免登录路由，需按官方文档用控制器或 `route.php` 暴露 `/addons/starloft_sms_relay/apiRelay` 与 `/addons/starloft_sms_relay/apiAdmin`（待确认） |
| 一律 401 `missing required headers` | 站点 Nginx 未透传 `X-*` 头（部分 WAF 会丢弃下划线/自定义头），检查反代配置 |
| 401 `invalid signature` | 签名串必须是**发送的原始请求体字节**；GET/DELETE 签空串；两边都别做 JSON 重排 |
| 平台返回 `code:403` | 平台账号实名等级不足或该 API 密钥权限不含对应端点 |
| 管理页 403 | `?token=` 与管理令牌不一致，或未配置管理令牌 |
| 日志表无数据 | 检查「记录调用日志」开关与建表是否成功 |

## 8. 技术支持

- 版本：v1.0.0 ｜ 兼容：智简魔方财务版 3.7.6+
- 平台 API 文档：<https://docs.starloft.cn/sms/api/v1>

---

© 2026 StarLoft. All Rights Reserved.