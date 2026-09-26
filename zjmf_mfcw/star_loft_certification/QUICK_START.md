# StarLoft 人脸核验(FV)插件（财务版）— 快速开始

## 📦 安装

1. 将 `star_loft_certification` 上传到 `/public/plugins/certification/star_loft_certification/`
2. 后台：`系统设置 → 实名认证设置 → 接口设置` → 找到插件 → 点击「安装」
3. 点击「配置」：

```
API地址:    https://www.starloft.cn/api
API Key:    your_api_key_here
API Secret: your_api_secret_here
人脸核验:   fv_auth（默认）
```

4. 在星楼网络平台[用户中心 → API密钥管理](https://console.starloft.cn/admin/login)获取 API Key / Secret

## 🚀 使用

- **个人实名**：填写姓名、身份证号 → 提交后跳转人脸核验页，完成有源人脸比对。
- **企业实名**：填写企业名称、统一社会信用代码、法人姓名、法人身份证号 → 法人完成人脸核验。

## ⚙️ 常用配置

- 默认直接人脸核验（无需改动）。

## 🔧 故障排查

- API 连接/鉴权失败：核对 API 地址（含 `/api`）、API Key/Secret、服务器时间（±5 分钟）
- 摄像头无法使用：确认用户使用 Chrome/Edge 最新版，并授权摄像头（webRTC）
- 一直「认证中」：确认平台侧订单状态、回调地址可从外网访问

## 📚 文档

- [完整使用文档](README.md)
- [星楼网络 API文档](https://docs.starloft.cn/api)

## 💡 版本信息

- 版本: v2.0.0
- 兼容: 智简魔方财务版 3.7.6+
- 作者: StarLoft

---

**祝使用愉快！** 🎉