# 快速开始（StarLoft 短信中转 · 智简魔方财务版）

## 1. 装

1. 上传 `starloft_sms_relay` 到 `/public/plugins/addons/starloft_sms_relay/`
2. 后台插件管理 → 「StarLoft 短信中转」→ 安装 → 配置

## 2. 配

| 项 | 值 |
|---|---|
| 平台API地址 | `https://api.starloft.cn` |
| 平台API Key / Secret | 本站点在星楼网络的 API 密钥（用户中心 → API密钥管理） |
| 管理令牌 | 自定义口令，管理页要用 |

## 3. 发密钥

打开管理页：**后台「插件」菜单下本插件的入口**，或用下面的免登录地址（`token` 换成你的管理令牌）：

```
https://你的站点/addons/starloft_sms_relay/apiAdmin?token=你的管理令牌
```

「新建客户密钥」填客户名称 → 生成后**立即复制 Secret**（只显示一次）交给客户。客户欠费或泄露时用「停用 / 重置密钥」。

**别忘了充值**：客户调用是**按次扣预付余额**的（单价在插件配置里设，0=免费）。在管理页每行的「金额 + 充值/扣减」给客户加钱，余额为 0 时调用会直接返回 `402 余额不足`。

## 4. 客户怎么调

```
POST https://你的站点/addons/starloft_sms_relay/apiRelay?endpoint=sms/send
```

请求头（与平台一致，密钥换成中转密钥）：

```
X-Api-Key: sk_xxxx
X-Sign: HMAC-SHA256(中转Secret, 原始请求体) 的小写十六进制
X-Sign-Version: hmac_sha256
X-Timestamp: Unix 秒（±300 秒内）
```

请求体 = 平台同端点的 JSON 入参原文；响应 = 平台响应原文。

自测：

```bash
B='{"phone_number_set":["13800000000"],"template_id":123,"template_params":["123456"]}'
TS=$(date +%s)
SIG=$(printf '%s' "$B" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
curl -sS -X POST "https://你的站点/addons/starloft_sms_relay/apiRelay?endpoint=sms/send" \
  -H "Content-Type: application/json" -H "X-Api-Key: $KEY" -H "X-Sign: $SIG" \
  -H "X-Sign-Version: hmac_sha256" -H "X-Timestamp: $TS" -d "$B"
```

预期：`invalid api key`=密钥错；平台业务错误（如 `模板不存在`、权限/实名提示）说明**中转已打通**。

## 5. 常看两处

- 管理页底部「最近调用日志」：谁调了哪个端点、结果与耗时
- 插件配置页「测试平台连通/鉴权」：确认站点平台密钥可用

## 6. 必读提醒

- 客户调用**按次扣预付余额**（单价在插件配置里设），余额为 0 会被 `402` 拒绝，记得在管理页充值
- 客户调用**消耗站点平台账号的余额/资源包**，注意额度
- 短信端点要求平台账号**个人实名**
- 短信回执与上行由平台侧产生，客户可用 `sms/report`、`sms/replies` 主动查询
- 想直接复用官方 SDK：站点 Nginx 加一条 `rewrite ^/v1/(.*)$ /addons/starloft_sms_relay/apiRelay?endpoint=$1 last;`，客户只改 `api_url` 与密钥

完整说明见 [README.md](./README.md)。