# StarLoft 短信服务(SMS)插件（v10）— 快速开始

## 📦 安装

1. 将 `starloft_sms` 上传到 `/public/plugins/sms/starloft_sms/`（目录名与插件命名空间/类名必须一致）
2. 后台：`短信设置 → 接口管理` → 找到「StarLoft 短信服务」→ 点击「安装」（自动导入全部默认模板）
3. 点击「配置」：

```
API地址:      https://api.starloft.cn
API Key:      your_api_key_here
API Secret:   your_api_secret_here
默认短信签名内容:  星楼网络（填平台「签名管理」中已通过的签名内容本身，不含【】；留空自动使用最新已通过的签名）
默认短信类型:  notify
```

4. 在星楼网络平台[用户中心 → API密钥管理](https://console.starloft.cn/admin/login)获取 API Key / Secret

## 🚀 使用

1. 「模板管理」页勾选需要的模板 → 提交审核（自动上报星楼网络平台，平台/上游审核通过后状态为「通过」）。
2. 「发送设置」页选择 StarLoft 短信服务 → 保存 → 测试发送。

## ⚙️ 支持方向

- **国内短信**（sendCnSms / getCnTemplate / createCnTemplate / putCnTemplate / deleteCnTemplate）

## 🔧 故障排查

- API 连接/鉴权失败：核对 API 地址（含 `/api`）、API Key/Secret、服务器时间（±5 分钟）
- 模板状态一直「审核中」：请在星楼网络平台后台完成短信模板审核
- 签名不规范：确认签名已在平台审核通过；`sign_name` 填的是平台「签名管理」里的签名内容本身（不含【】），不是自拟的签名名称，留空则自动使用最新已通过的签名
- 余额不足：提示管理员在星楼网络平台充值

## 📚 文档

- [完整使用文档](README.md)
- [星楼网络 API文档](https://docs.starloft.cn/api)

## 💡 版本信息

- 版本: v3.0.0
- 兼容: 智简魔方业务系统 v10
- 作者: StarLoft

---

**祝使用愉快！** 🎉
