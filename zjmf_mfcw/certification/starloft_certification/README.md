# StarLoft 人脸核验(FV)插件（智简魔方财务版）

## 插件简介

对接「星楼网络」人脸核验(FV)服务的人脸识别认证插件，适用于**智简魔方财务版（IDC）**。
平台 API 域名为 `api.starloft.cn`，统一走 `/v1/fv` 接口前缀；人脸核验承接页在 `service.starloft.cn`。

> 本插件（_fv）只做「有源人脸识别」（`fv_auth`）。IDC 用户仅实名第一次，不保留照片用于无源复用，因此只调用有源人脸核验。

## 功能特性

- ✅ 个人实名：姓名 + 身份证号 + 人脸核验（`fv_auth`）
- ✅ 企业实名：**先企业要素核验**（三要素/四要素，后台可配；对接平台「要素核验」产品）通过后，**再发起法人扫脸**（`fv_auth`）
- ✅ 支持平台异步通知（`notify_url` 回调）与认证完成回跳，收到结果先调用 `/v1/fv/result` 校对，对齐上游后再落地本地
- ✅ 支持跳过平台异步通知（只靠主动查询/校对同步结果）
- ✅ HMAC-SHA256 v2 签名认证（含方法、路径、时间戳、nonce 与正文摘要）
- ✅ 幂等保护：认证中任务自动复用，避免重复发单扣费

## 目录结构

```
starloft_certification/
├── StarloftCertificationPlugin.php   # 插件主类（入口）
│   ├── install() / uninstall()
│   ├── personal()            # 个人有源人脸核验
│   ├── company()             # 企业法人扫脸
│   ├── collectionInfo()      # 前台自定义字段
│   ├── getStatus()           # 查询/校对核验状态（AJAX 轮询）
│   ├── callback()            # 平台异步通知入口（核验结果推送）
│   └── result()              # 认证完成回跳页
├── logic/
│   └── FvSdk.php            # StarLoft SDK（API 通信 + HMAC 签名 + 错误分类）
├── controller/
│   └── IndexController.php  # 外部访问入口（result / callback / getStatus）
├── config.php                # 插件配置项
├── plugin.json               # 插件元数据
├── README.md                 # 本文档
└── QUICK_START.md            # 快速开始
```

## 安装步骤

1. 将 `starloft_certification` 文件夹上传到 `/public/plugins/certification/starloft_certification/`。
2. 后台进入 `系统设置 → 实名认证设置 → 接口设置`，找到「StarLoft 人脸核验」并点击「安装」。
3. 安装后点击「配置」，填写 API 地址 / API Key / API Secret。

## 配置说明

| 配置项 | 必填 | 说明 |
|--------|------|------|
| API地址 | ✅ | 星楼网络平台 API 地址，如 `https://api.starloft.cn` |
| API Key | ✅ | 在星楼网络平台后台「用户中心 → API密钥管理」获取 |
| API Secret | ✅ | 在星楼网络平台后台获取，用于 HMAC 签名 |
| 人脸核验体服务标识 | - | 默认 `fv_auth`（有源人脸） |
| 跳过平台异步通知 | - | 启用后不回传 notify_url，结果由主动查询/校对同步 |
| 落地前结果校对 | - | 启用后收到结果先调用 `/v1/fv/result` 校对再写本地 |
| 最小年龄限制（周岁） | - | 0 表示不限制；如填 `18` 即要求年满 18 周岁（成年） |
| 最大年龄限制（周岁） | - | 0 表示不限制；与最小年龄同时填写即为区间限制，如 `18` / `60` |
| 企业要素核验方式 | - | 企业实名的核验方式：`三要素`（默认）/ `四要素` / `关闭`；三要素 = 企业名称+统一社会信用代码+法人姓名，四要素再加法人身份证号 |
| 单次认证费用 / 免费认证次数 | - | 智简魔方财务版系统字段 |

## 年龄限制

平台核验接口**不返回年龄**，插件按身份证号推算：

- 取身份证第 7-14 位出生日期（18 位证 `YYYYMMDD`；15 位旧证 `YYMMDD` 年份补 19），按自然日计算**周岁**（生日未到则减 1）；
- **在发起人脸核验之前校验**，不符合直接拒绝，**不会调用平台接口、不产生任何费用**；
- 拒绝后本地认证记录置为「未通过」（`status=2`），失败原因写入 `auth_fail`，用户可看到具体提示；
- 同时配置最小与最大年龄即为区间限制（如 18~60 周岁）；
- 配置为 `0` 表示该项不限制；两项都为 `0` 时**完全不做校验**；
- 启用限制但身份证号无法解析（长度/日期非法等）时，按「格式不正确」直接拒绝，避免绕过。

> 注意：平台「有源人脸」（`fv_auth`）本身只支持中国大陆居民身份证，因此年龄只按大陆身份证解析规则推算。

## 企业实名流程（要素核验 → 法人扫脸）

企业实名分两步，**先要素核验、再扫脸**：

1. 用户填写 企业名称 / 统一社会信用代码 / 法人姓名 / 法人身份证号；
2. 插件按后台「企业要素核验方式」调用平台**要素核验**接口（同步、即时出结果）：
   - 三要素：`POST /v2/ev/biz3/verify`（企业名称 + 统一社会信用代码 + 法人姓名）
   - 四要素：`POST /v2/ev/biz4/verify`（三要素 + 法人身份证号）
3. 要素核验未通过 → 直接拒绝（不发起扫脸，不产生人脸核验费用）；
4. 要素核验通过 → 与个人实名一致，发起法人扫脸（`fv_auth`），走既有轮询/通知/回跳流程。

**平台侧前置要求**（站点管理员检查）：

- 平台 API 密钥权限需同时包含 `ev_biz3` / `ev_biz4` 与 `fvs_auth`（旧密钥为 `fv_auth`）；
- 平台账号需已开通「要素核验」产品，并在后台「要素核验 → 产品配置」设置三/四要素单价；
- 要素核验按次独立计费（三/四要素各有单价），与人脸核验计费分开。

## 外部回调地址（由插件自动登记给平台）

| 用途 | 地址 |
|------|------|
| 异步通知（核验结果推送） | `{站点域名}/certification/starloft_certification/callback?uid={用户ID}` |
| 认证完成回跳（默认） | 会员中心实名认证页，默认 `{站点域名}/user/certification` |
| 认证完成回跳（可选） | 插件结果页 `{站点域名}/certification/starloft_certification/result` |
| 状态查询（页面轮询） | `{站点域名}/certification/starloft_certification/getStatus` |

- 回跳地址在插件配置「认证完成回跳地址」中维护：可填站内路径（如 `/user/certification`，自动补全为本站域名）或完整 `https` 地址；留空则停留平台认证结果页、不发起回跳。
- 以上 `/certification/starloft_certification/*` 地址由插件根目录的 `controller/IndexController.php` 提供（财务版实名认证插件的外部访问入口必须放在 `controller/` 下，缺失会返回框架 404）。
- 异步通知为服务器到服务器调用（无登录态），插件按 `X-Api-Key` 对应的 `api_secret` 校验 `sign` 签名后，先调用 `/v1/fv/result` 校对再落地本地，并始终返回 HTTP 2xx，避免平台反复补推。
- 若站点无法被平台公网访问，请在配置中启用「跳过平台异步通知」，由页面轮询与结果校对同步。

## 平台对接说明

- 接口前缀：`/v1/fv`（如 `/v1/fv/auth`、`/v1/fv/self`、`/v1/fv/result`、`/v1/fv/best-img`、`/v1/fv/media`）
- 鉴权头（每个请求必带）：`X-Api-Key`、`X-Sign`（签名原文为方法、路径、查询串、时间戳、nonce、正文 SHA-256）、`X-Sign-Version: hmac_sha256_v2`、`X-Timestamp`、`X-Nonce`
- 人脸核验子产品服务标识：`fv_auth`（有源人脸）/ `fv_self`（无源人脸）

## 技术支持

- **版本**: v2.3.0
- **兼容版本**: 智简魔方财务版 3.7.6+
- **作者**: StarLoft
- **文档**: https://docs.starloft.cn/fv/plugin

## 许可协议

MIT License

---

© 2026 StarLoft. All Rights Reserved.
