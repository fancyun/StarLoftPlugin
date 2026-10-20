# StarLoft FV 人脸核验认证插件（智简魔方业务系统 v10）

## 插件简介

对接「星楼网络」人脸核验(FV)服务的人脸识别认证插件，适用于**智简魔方业务系统 v10**。
平台 API 域名为 `api.starloft.cn`，统一走 `/v1/fv` 接口前缀；人脸核验承接页在 `service.starloft.cn`。

> 本插件（_fv）只做「有源人脸识别」（`fv_auth`）。IDC 用户仅实名第一次，不保留照片用于无源复用，因此只调用有源人脸核验。

## 功能特性

- ✅ 个人实名：姓名 + 身份证号 + 人脸核验（`fv_auth`）
- ✅ 企业实名：企业名称 + 统一社会信用代码 + 法人姓名 + 法人身份证号 + 法人人脸核验（`fv_auth`）
- ✅ 按 v10 实名认证接口规范开发（`StarloftCertificationPerson` / `StarloftCertificationCompany` / `StarloftCertificationCollectionInfo`）
- ✅ 收到结果后主动调用 `/v1/fv/result` 做结果校对，落地前对齐上游
- ✅ 支持跳过平台异步通知（只靠主动查询/校对同步结果）
- ✅ 异步回调（notify_url）+ 前台状态轮询 + HMAC-SHA256 v2 签名认证
- ✅ 幂等保护：认证中任务自动复用，避免重复发单扣费

## 目录结构

```
starloft_certification/
├── StarloftCertification.php                    # 插件入口文件（命名空间 certification\starloft_certification）
├── config.php                         # 插件配置项
├── controller/
│   └── IndexController.php            # 外部回调控制器
│       ├── notifyHandle()             # 异步通知处理（校验签名 → 结果校对 → 落地本地）
│       ├── result()                   # 认证完成回跳页
│       └── status()                   # 状态查询（AJAX 轮询）
└── logic/
    └── FvSdk.php                     # StarLoft FV SDK（API 通信 + HMAC 签名 + 错误分类）
```

## 安装步骤

1. 将 `starloft_certification` 文件夹上传到 `/public/plugins/certification/starloft_certification/`。
2. 登录 v10 管理后台，进入 `实名认证 → 接口管理`，找到「StarLoft FV人脸核验认证」并点击「安装」。
3. 安装后点击「配置」，填写 API 地址 / API Key / API Secret。

## 配置说明

| 配置项 | 必填 | 说明 |
|--------|------|------|
| API地址 | ✅ | FV 平台 API 地址，如 `https://api.starloft.cn` |
| API Key | ✅ | 在 FV 平台后台「用户中心 → API密钥管理」获取 |
| API Secret | ✅ | 在 FV 平台后台获取，用于 HMAC 签名 |
| 人脸核验体服务标识 | - | 默认 `fv_auth`（有源人脸） |
| 跳过平台异步通知 | - | 启用后不回传 notify_url，结果由主动查询/校对同步 |
| 落地前结果校对 | - | 启用后收到结果先调用 `/v1/fv/result` 校对再写本地 |
| 最小年龄限制（周岁） | - | 0 表示不限制；如填 `18` 即要求年满 18 周岁（成年） |
| 最大年龄限制（周岁） | - | 0 表示不限制；与最小年龄同时填写即为区间限制，如 `18` / `60` |
| 认证完成回跳地址 | - | 留空使用插件内置结果页 |
| 单次认证费用 / 免费认证次数 | - | v10 实名认证系统必需字段 |

## 年龄限制

平台核验接口**不返回年龄**，插件按身份证号推算：

- 取身份证第 7-14 位出生日期（18 位证 `YYYYMMDD`；15 位旧证 `YYMMDD` 年份补 19），按自然日计算**周岁**（生日未到则减 1）；
- **在发起人脸核验之前校验**，不符合直接拒绝，**不会调用平台接口、不产生任何费用**；
- 拒绝后本地认证记录置为「未通过」（`status=2`），失败原因写入 `auth_fail`，用户可看到具体提示；
- 同时配置最小与最大年龄即为区间限制（如 18~60 周岁）；
- 配置为 `0` 表示该项不限制；两项都为 `0` 时**完全不做校验**；
- 启用限制但身份证号无法解析（长度/日期非法等）时，按「格式不正确」直接拒绝，避免绕过。

> 注意：平台「有源人脸」（`fv_auth`）本身只支持中国大陆居民身份证，因此年龄只按大陆身份证解析规则推算。

## 回调地址

| 地址 | 用途 |
|------|------|
| `/certification/starloft_certification/index/notifyHandle` | 异步通知（校验签名 → 结果校对 → 落地） |
| `/certification/starloft_certification/index/result` | 认证完成回跳页 |

## 平台对接说明

- 接口前缀：`/v1/fv`（如 `/v1/fv/auth`、`/v1/fv/self`、`/v1/fv/result`、`/v1/fv/best-img`、`/v1/fv/media`）
- 鉴权头（每个请求必带）：`X-Api-Key`、`X-Sign`（签名原文为方法、路径、查询串、时间戳、nonce、正文 SHA-256）、`X-Sign-Version: hmac_sha256_v2`、`X-Timestamp`、`X-Nonce`
- 人脸核验子产品服务标识：`fv_auth`

## 技术支持

- **版本**: v2.1.0
- **兼容版本**: 智简魔方业务系统 v10
- **作者**: StarLoft
- **文档**: https://docs.starloft.cn/fv/plugin/v10_fv

## 许可协议

MIT License

---

© 2026 StarLoft. All Rights Reserved.
