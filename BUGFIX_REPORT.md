# Plugin 目录 Bug 审计与修复报告

审计范围：`plugin/` 下 69 个 PHP 文件（智简魔方 `zjmf_mfcw` / `zjmf_v10` 两代插件的短信、人脸、中转插件 + `epay` 覆盖式补丁）。
验证方式：便携 PHP 8.3.35 `php -l` 全量语法检查 + 关键逻辑脚本验证；签名契约以 Go 服务端实现与官方文档为准。

---

## P0 — 致命：HMAC 签名原文换行符写错（导致全部验签失败）

**问题**：12 个文件的 `logic/sdk/Client.php` 与 `logic/Relay.php` 中，签名原文用
`implode("\\n", [...])` 拼接。PHP 双引号字符串里 `"\\n"` 是**字面反斜杠 + n**（十六进制 `5c 6e`），
**不是换行符**（`0a`）。而平台/服务端一致地用真换行 `"\n"` 拼接。

**影响**：所有涉及 HMAC 的调用（短信发送、人脸发起/查询、中转请求的客户端验签与转发签名）
计算出的签名与对方期望值**永远不一致**，即插件核心功能整体不可用。

**权威依据**：
- `main/backend/internal/utils/safe_http.go:122` `CanonicalRequest()` → `strings.Join([...], "\n")`
- `main/docs/api-v1.md:42-46,53` → 明确 `implode("\n", [$method, $path, $rawQuery, $timestamp, $nonce, $bodyHash])`
- `plugin/epay/patch/includes/lib/sms/StarLoft.php:53`、`StarLoftCertify.php:109` → 手写参考实现本就用 `"\n"`

**修复**：全部改为 `implode("\n", ...)`（共 13 处 / 12 个 PHP 文件）：

```
zjmf_v10/addon/starloft_fv_relay/logic/Relay.php
zjmf_v10/addon/starloft_fv_relay/logic/sdk/Client.php
zjmf_v10/addon/starloft_sms_relay/logic/Relay.php
zjmf_v10/addon/starloft_sms_relay/logic/sdk/Client.php
zjmf_v10/certification/starloft_certification/logic/sdk/Client.php
zjmf_v10/sms/starloft_sms/logic/sdk/Client.php
zjmf_mfcw/addons/starloft_fv_relay/logic/Relay.php
zjmf_mfcw/addons/starloft_fv_relay/logic/sdk/Client.php
zjmf_mfcw/addons/starloft_sms_relay/logic/Relay.php
zjmf_mfcw/addons/starloft_sms_relay/logic/sdk/Client.php
zjmf_mfcw/certification/starloft_certification/logic/sdk/Client.php
zjmf_mfcw/sms/starloftsms/logic/sdk/Client.php
```
另 4 个 `README.md` 的示例代码同样更正，避免开发者照抄错误写法。

---

## P1 — 功能/一致性问题（已修复）

1. **帮助页示例字段名错误**（4 个 relay 的 `logic/Admin.php`）
   - 短信：`"params"` → `"template_params"`（官方文档 `api-v1.md:418` 定义）
   - 人脸：`"id_number"` → `"id_card"`（官方文档 `api-v1.md:119` 定义）
   - 客户照抄示例会因字段名错误导致参数丢失/发起失败。

2. **配置项 `require_reconcile` 声明但从未读取**（certification 插件，v10 + mfcw）
   - 后台提供「落地前结果校对」开关，但代码无条件调用 `reconcile()`，开关形同虚设。
   - 修复：新增 `requireReconcile()`，controller 按开关决定是否额外调 `/v1/fv/result`。

3. **v10 通知回写 `auth_fail` 语义错误**
   - 处理中等非失败状态也把文案写进 `auth_fail` 字段，前端可能误当失败原因展示。
   - 修复：仅 `status===2`（未通过）才写失败原因，其余留空，与 mfcw 版一致。

---

## P2 — 金额安全（已修复）

**epay 补丁重复扣认证费窗口**（`user/alipaycertok.php`、`user/starloftnotify.php`）
- 原来执行条件更新 `update ... set cert=1 ... and cert=0` 后**无条件**调用 `changeUserMoney()`。
- 并发收到重复通知时，两个请求都可能通过前置读取检查，导致认证费被扣两次。
- 修复：包进 `if($DB->exec(...))`，以受影响行数为准再扣费（幂等），与 epay 框架既有写法一致。

---

## 已澄清的误报（非 bug）

- v10 `getStatus()` **已**调用 `getCurrentUserCertiRecord()` 兜底，并非遗漏。
- `preg_match('/[\\r\\n]/', $nonce)` 的单引号写法是**正确**的：正则中 `\r\n` 表示 CR/LF。
- `printf '%s\\n%s'` （单引号串）也是**正确**的：需要字面 `\n` 交给 shell。
- 只有出现在**双引号**串且用于 HMAC 原文拼接的 `"\\n"` 才是 bug。

---

## 验证结果

- `php -l`：全部 69 个 PHP 文件语法通过。
- 脚本验证：修复后 `handle()` / `forward()` / `sdk/Client` 三处 canonical 与 Go `CanonicalRequest` 逐字段一致。
- 脚本验证：通知签名（`biz_no/cost/result_code/result_message/status` 排序拼接）平台侧与插件侧一致。
- 全部 8 个 `plugin.json` 均可被 JSON 解析。
