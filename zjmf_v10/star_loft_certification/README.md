# StarLoft FV 人脸核验认证插件（智简魔方业务系统 v10）

## 插件简介

对接「星楼网络」人脸核验(FV)服务的人脸识别认证插件，适用于**智简魔方业务系统 v10**。
平台 API 域名含 `www.starloft.cn` / `service.starloft.cn`，统一走 `/api/fv` 接口前缀。

> 本插件（_fv）只做「有源人脸识别」（`fv_auth`）。IDC 用户仅实名第一次，不保留照片用于无源复用，因此只调用有源人脸核验。

## 功能特性

- ✅ 个人实名：姓名 + 身份证号 + 人脸核验（`fv_auth`）
- ✅ 企业实名：企业名称 + 统一社会信用代码 + 法人姓名 + 法人身份证号 + 法人人脸核验（`fv_auth`）
- ✅ 按 v10 实名认证接口规范开发（`StarLoftCertificationPerson` / `StarLoftCertificationCompany` / `StarLoftCertificationCollectionInfo`）
- ✅ 收到结果后主动调用 `/api/fv/result` 做结果校对，落地前对齐上游
- ✅ 支持跳过平台异步通知（只靠主动查询/校对同步结果）
- ✅ 异步回调（notify_url）+ 前台状态轮询 + HMAC-SHA256 签名认证
- ✅ 幂等保护：认证中任务自动复用，避免重复发单扣费

## 目录结构

```
star_loft_certification/
├── StarLoftCertification.php                    # 插件入口文件（命名空间 certification\star_loft_certification）
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

1. 将 `star_loft_certification` 文件夹上传到 `/public/plugins/certification/star_loft_certification/`。
2. 登录 v10 管理后台，进入 `实名认证 → 接口管理`，找到「StarLoft FV人脸核验认证」并点击「安装」。
3. 安装后点击「配置」，填写 API 地址 / API Key / API Secret。

## 配置说明

| 配置项 | 必填 | 说明 |
|--------|------|------|
| API地址 | ✅ | FV 平台 API 地址，如 `https://www.starloft.cn/api` |
| API Key | ✅ | 在 FV 平台后台「用户中心 → API密钥管理」获取 |
| API Secret | ✅ | 在 FV 平台后台获取，用于 HMAC 签名 |
| 人脸核验体服务标识 | - | 默认 `fv_auth`（有源人脸） |
| 跳过平台异步通知 | - | 启用后不回传 notify_url，结果由主动查询/校对同步 |
| 落地前结果校对 | - | 启用后收到结果先调用 `/api/fv/result` 校对再写本地 |
| 认证完成回跳地址 | - | 留空使用插件内置结果页 |
| 单次认证费用 / 免费认证次数 | - | v10 实名认证系统必需字段 |

## 回调地址

| 地址 | 用途 |
|------|------|
| `/certification/star_loft_certification/index/notifyHandle` | 异步通知（校验签名 → 结果校对 → 落地） |
| `/certification/star_loft_certification/index/result` | 认证完成回跳页 |

## 平台对接说明

- 接口前缀：`/api/fv`（如 `/api/fv/start`、`/api/fv/result`、`/api/fv/balance/query`）
- 鉴权头（每个请求必带）：`X-Api-Key`、`X-Sign`（`hex(HMAC-SHA256(api_secret, 原始请求体))`）、`X-Sign-Version: hmac_sha256`、`X-Timestamp`
- 人脸核验子产品服务标识：`fv_auth`

## 技术支持

- **版本**: v2.0.0
- **兼容版本**: 智简魔方业务系统 v10
- **作者**: StarLoft
- **文档**: https://docs.starloft.cn/fv/plugin/v10_fv

## 许可协议

MIT License

---

© 2026 StarLoft. All Rights Reserved.