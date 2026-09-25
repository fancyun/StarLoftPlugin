# StarLoft 人脸核验(FV)插件（智简魔方财务版）

## 插件简介

对接「星楼网络」人脸核验(FV)服务的人脸识别认证插件，适用于**智简魔方财务版（IDC）**。
平台 API 域名含 `www.starloft.cn` / `service.starloft.cn`，统一走 `/api/fv` 接口前缀。

> 本插件（_fv）只做「有源人脸识别」（`fv_auth`）。IDC 用户仅实名第一次，不保留照片用于无源复用，因此只调用有源人脸核验。

## 功能特性

- ✅ 个人实名：姓名 + 身份证号 + 人脸核验（`fv_auth`）
- ✅ 企业实名：企业名称 + 统一社会信用代码 + 法人姓名 + 法人身份证号 + 法人人脸核验（`fv_auth`）
- ✅ 收到结果后主动调用 `/api/fv/result` 做结果校对，落地前对齐上游
- ✅ 支持跳过平台异步通知（只靠主动查询/校对同步结果）
- ✅ HMAC-SHA256 签名认证（X-Api-Key / X-Sign / X-Sign-Version / X-Timestamp）
- ✅ 幂等保护：认证中任务自动复用，避免重复发单扣费

## 目录结构

```
star_loft_fv_for_zjmf_mfcw/
├── StarLoftFvForZjmfMfcwPlugin.php   # 插件主类（入口）
│   ├── install() / uninstall()
│   ├── personal()            # 个人有源人脸核验
│   ├── company()             # 企业法人扫脸
│   ├── collectionInfo()      # 前台自定义字段
│   └── getStatus()           # 查询/校对核验状态
├── logic/
│   └── FvSdk.php            # StarLoft SDK（API 通信 + HMAC 签名 + 错误分类）
├── config.php                # 插件配置项
├── plugin.json               # 插件元数据
├── README.md                 # 本文档
└── QUICK_START.md            # 快速开始
```

## 安装步骤

1. 将 `star_loft_fv_for_zjmf_mfcw` 文件夹上传到 `/public/plugins/certification/star_loft_fv_for_zjmf_mfcw/`。
2. 后台进入 `系统设置 → 实名认证设置 → 接口设置`，找到「StarLoft 人脸核验」并点击「安装」。
3. 安装后点击「配置」，填写 API 地址 / API Key / API Secret。

## 配置说明

| 配置项 | 必填 | 说明 |
|--------|------|------|
| API地址 | ✅ | 星楼网络平台 API 地址，如 `https://www.starloft.cn/api` |
| API Key | ✅ | 在星楼网络平台后台「用户中心 → API密钥管理」获取 |
| API Secret | ✅ | 在星楼网络平台后台获取，用于 HMAC 签名 |
| 人脸核验体服务标识 | - | 默认 `fv_auth`（有源人脸） |
| 跳过平台异步通知 | - | 启用后不回传 notify_url，结果由主动查询/校对同步 |
| 落地前结果校对 | - | 启用后收到结果先调用 `/api/fv/result` 校对再写本地 |
| 单次认证费用 / 免费认证次数 | - | 智简魔方财务版系统字段 |

## 平台对接说明

- 接口前缀：`/api/fv`（如 `/api/fv/start`、`/api/fv/result`、`/api/fv/balance/query`）
- 鉴权头（每个请求必带）：`X-Api-Key`、`X-Sign`（`hex(HMAC-SHA256(api_secret, 原始请求体))`）、`X-Sign-Version: hmac_sha256`、`X-Timestamp`
- 人脸核验子产品服务标识：`fv_auth`（有源人脸）/ `fv_self`（无源人脸）

## 技术支持

- **版本**: v2.0.0
- **兼容版本**: 智简魔方财务版 3.7.6+
- **作者**: StarLoft
- **文档**: https://docs.starloft.cn/fv/plugin

## 许可协议

MIT License

---

© 2026 StarLoft. All Rights Reserved.