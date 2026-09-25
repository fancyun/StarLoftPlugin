# StarLoft 短信服务(SMS)插件（智简魔方业务系统 v10）

## 插件简介

对接「星楼网络」短信服务(SMS)的短信插件，适用于**智简魔方业务系统 v10**，按 v10「短信接口开发」规范实现（**平台模板型 · 国内短信**）。

平台 API 域名为 `api.starloft.cn`，统一走 `/v1/sms` 接口前缀。

## 功能特性

- ✅ 支持方向：**国内短信**（实现 `sendCnSms` / `getCnTemplate` / `createCnTemplate` / `putCnTemplate` / `deleteCnTemplate`）
- ✅ 安装时自动导入 v10 全部 21 个系统默认短信模板（`config/smsTemplate.php`）
- ✅ 模板管理：模板提交后进入星楼网络平台审核，状态实时回查（审核中/通过/未通过）
- ✅ 模板发送：按平台模板 ID 发送，`@var(变量)` 由插件按系统参数顺序自动转换
- ✅ 兼容旧版 `sendSms()`（模板型/直发型）调用
- ✅ HMAC-SHA256 签名认证（X-Api-Key / X-Sign / X-Sign-Version / X-Timestamp）

## 目录结构

```
star_loft_sms_for_zjmf_v10/
├── StarLoftSmsForZjmfV10.php        # 插件入口文件（命名空间 sms\star_loft_sms_for_zjmf_v10）
├── config/
│   └── smsTemplate.php              # v10 系统默认短信模板（安装时导入）
├── logic/
│   └── SmsSdk.php                   # StarLoft SMS SDK（API 通信 + HMAC 签名 + 错误分类）
├── config.php                       # 插件配置项
├── plugin.json                      # 插件元数据
├── README.md                        # 本文档
└── QUICK_START.md                   # 快速开始
```

## 安装步骤

1. 将 `star_loft_sms_for_zjmf_v10` 文件夹上传到 `/public/plugins/sms/star_loft_sms_for_zjmf_v10/`（**目录名必须与插件命名空间/类名一致**）。
2. 登录 v10 管理后台，进入 `短信设置 → 接口管理`，找到「StarLoft 短信服务」点击「安装」（自动导入全部默认模板）。
3. 安装后点击「配置」，填写 API 地址 / API Key / API Secret / 默认短信签名内容 / 默认短信类型。
4. 在「模板管理」页将模板提交审核（自动上报星楼网络平台，平台/上游审核通过后状态为「通过」）。
5. 在「发送设置」页选择 StarLoft 短信服务，保存后即可测试发送。

## 配置说明

| 配置项 | 必填 | 说明 |
|--------|------|------|
| API地址 | ✅ | 星楼网络平台 API 地址，如 `https://api.starloft.cn` |
| API Key | ✅ | 在星楼网络平台后台「用户中心 → API密钥管理」获取 |
| API Secret | ✅ | 在星楼网络平台后台获取，用于 HMAC 签名 |
| 默认短信签名内容 | - | 平台「短信服务 → 签名管理」中已审核通过的签名内容本身（不含【】，如 `星楼网络`），不是自拟的签名名称；留空则自动使用该账号最新一条已通过的签名 |
| 默认短信类型 | - | verify / notify（暂不支持营销） |
| 单条短信费用 / 免费短信条数 | - | v10 短信系统必需字段 |

## 接口说明（v10 规范）

| 方法 | 说明 |
|------|------|
| `getCnTemplate($params)` | 获取国内模板状态，返回 `template.template_status`（0未提交/1审核中/2通过/3未通过） |
| `createCnTemplate($params)` | 创建国内模板（title/content/config），内容 `@var(name)` 自动转换为平台 `@` 顺序占位 |
| `putCnTemplate($params)` | 修改国内模板（template_id/title/content/config），重置待审核并重新报备上游 |
| `deleteCnTemplate($params)` | 删除国内模板（template_id/config） |
| `sendCnSms($params)` | 发送国内短信（mobile/content/template_id/templateParam/config），按模板 ID 走平台发送 |

## 平台对接说明

- 模板管理：`POST/GET/PUT/DELETE /v1/sms/templates[/{id}]`
- 发送：`POST /v1/sms/send`（`template_id` 为平台返回的模板 ID）
- 鉴权头（每个请求必带）：`X-Api-Key`、`X-Sign`（`hex(HMAC-SHA256(api_secret, 原始请求体))`）、`X-Sign-Version: hmac_sha256`、`X-Timestamp`
- 请求字段：`phone_number_set`（数组）、`template_id`、`template_params`（数组，按模板 `@` 顺序）、`sign_name`、`sms_type`

## 技术支持

- **版本**: v3.0.0
- **兼容版本**: 智简魔方业务系统 v10
- **作者**: StarLoft
- **文档**: https://docs.starloft.cn/sms/plugin

## 许可协议

MIT License

---

© 2026 StarLoft. All Rights Reserved.
