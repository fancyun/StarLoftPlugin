# StarLoft 彩虹易支付插件（短信 + 实名认证）

覆盖式补丁包，为**彩虹易支付（Epay）**接入「星楼网络」两项服务：

1. **短信通道**：后台短信接口设置里新增「星楼网络短信接口」，验证码/通知短信走星楼网络平台按模板发送；
2. **实名认证**：后台实名认证设置里新增「星楼网络人脸核身」，商户**注册时**填写姓名+身份证并完成人脸核验（配合原版「商户强制认证」开关，未实名不能收款）。

彩虹易支付没有插件目录式扩展点（支付插件才是 `plugins/xxx`），短信/实名都是「改系统原文件 + 后台配置」的形式，因此本包按同名路径覆盖安装，与短信宝、互亿无线的官方补丁做法一致。

## 一、目录结构（用 patch/ 下的文件覆盖到站点根目录）

```
patch/
├── admin/set.php                      # 改：短信接口设置加「星楼网络短信接口」；实名认证配置加「星楼网络人脸核身」
├── includes/functions.php             # 改：send_sms_common() 加 sms_api==5 分支
├── includes/lib/sms/StarLoft.php      # 新增：短信通道（HMAC 签名 + /v1/sms/send）
├── includes/lib/StarLoftCertify.php   # 新增：实名认证通道（/v1/fv/auth、/v1/fv/result、异步通知验签）
├── user/ajax.php                      # 改：注册时校验姓名/身份证并发起核身
├── user/ajax2.php                     # 改：certificate / cert_geturl 支持 cert_open==7
├── user/alipaycertok.php              # 改：核身完成回跳，查询结果并回写实名状态
├── user/certificate.php               # 改：实名页扫码模式与文案支持 cert_open==7
├── user/starloftnotify.php            # 新增：接收平台实名结果异步通知
└── user/reg.php                       # 改：注册表单加姓名/身份证字段，成功后跳转人脸核身
```

## 二、安装

1. **备份**原文件（至少上面列出的 6 个被修改的文件）；
2. 用 `patch/` 下的文件按同名路径覆盖站点根目录（新增文件直接放入）；
3. 后台改一次设置（保存即会清配置缓存），或删除 `pre_cache` 表里 `k='config'` 那一行；配置读取有缓存快照，不刷新会读不到新增配置项。

> 补丁基于公开的彩虹易支付源码结构（`admin/`、`includes/`、`user/` 与 `pre_config`/`pre_user` 表前缀）制作。若你的系统做过二改，覆盖前请先对比差异，必要时只挑对应片段合并。

## 三、短信配置

后台 → 系统设置 → 邮件和短信设置 → **短信接口设置**：

| 字段 | 填什么 |
|---|---|
| 接口选择 | **星楼网络短信接口** |
| AppId | 星楼网络平台后台「用户中心 → API密钥管理」的 **API Key** |
| AppKey | 同上的 **API Secret**（用于 HMAC-SHA256 签名） |
| 星楼网络API地址 | 留空默认 `https://api.starloft.cn` |
| 短信签名内容 | 平台「短信服务 → 签名管理」里**已审核通过的签名内容本身**（不含【】）；留空则用该账号最新一条已通过的签名 |
| 商户注册模板ID / 找回密码模板ID / 修改结算账号模板ID / 余额不足通知模板ID / 用户组到期通知模板ID / 订单投诉通知模板ID | 填**星楼网络平台「短信服务 → 模板管理」里已审核通过的模板 ID**（即平台模板主键）。留空即不发送对应通知 |

要点：

- 星楼网络**只支持模板发送**，不支持内容直发；短信文案与变量顺序在星楼网络平台侧维护，本通道按模板占位符顺序上送参数值；
- 原版这些字段的语义是「模板ID」或「模板文案」（短信宝是文案），本通道统一按**模板 ID** 解析；
- 各场景模板只需 1 个变量（原版只传 `code`：验证码、账单号、用户组名、订单号等）；
- 平台侧账号须完成**个人实名认证**，API Key 权限须为 `all` 或包含 `sms_send`。

## 四、实名认证配置

后台 → 系统设置 → **实名认证接口配置**：

| 字段 | 填什么 |
|---|---|
| 是否开启实名认证 | **星楼网络人脸核身** |
| 星楼网络API Key | 平台 API Key |
| 星楼网络API Secret | 平台 API Secret |
| 星楼网络API地址 | 留空默认 `https://api.starloft.cn` |
| 商户强制认证 | 开启后：注册时必须填姓名+身份证并发起人脸核身，且未实名不能收款（原版逻辑） |
| 实名认证费用 | 认证成功从商户余额扣除，留空或 0 为免费 |

## 五、工作流程

**短信**

```
注册/找回密码/结算账号修改 → send_sms($phone,$code,$scene)
余额不足 / 用户组到期 / 订单投诉 → MsgNotice / send_sms_common($phone,$tpl_code,['code'=>…])
        ↓
send_sms_common() → \lib\sms\StarLoft->send($phone,$param,$template_id,$sign)
        ↓
POST /v1/sms/send  {phone_number_set, template_id, template_params[按顺序], sign_name}
```

**实名认证（注册时）**

```
注册页（开启强制认证时显示姓名/身份证）
    ↓ 提交
user/ajax.php?act=reg → 校验验证码/姓名/身份证 → 建号 → POST /v1/fv/auth（带 return_url / notify_url）
    ↓ 返回 biz_no + site_url
写入 pre_user：cert=0, certmethod=4, certno, certname, certtoken=biz_no
    ↓ 前端跳转 site_url（人脸核身承接页）
用户完成扫脸
    ├─ 同步回跳 user/alipaycertok.php?state=… → POST /v1/fv/result(sync=1) → status=1 则 cert=1, certtime=NOW()
    └─ 异步通知 user/starloftnotify.php?uid=… → 验签（biz_no/cost/result_code/result_message/status 排序后 HMAC-SHA256）
                                             → 同样回写 cert=1（幂等，不重复扣认证费）
```

商户登录后也可在商户后台「实名认证」页自助补做（原版页面，已支持 `cert_open=7`）。

## 六、注意事项

- `user/starloftnotify.php` 必须能被**公网访问**（平台异步通知用）；若站点有 WAF/安全组限制请放行，否则只能靠回跳或登录后自助查询完成落地。
- 服务器需能出网访问 `https://api.starloft.cn`（HTTPS、curl 扩展）。
- `certmethod=4` 是本补丁新增的认证方式标记（原版 0-支付宝 / 1-微信 / 2-手机号三要素 / 3-人工）。若你的后台有「认证方式」文案映射表，请自行补一行「星楼网络人脸核身」。
- 注册环节若账号已创建但核身发起失败（余额不足、通道未配置等），不会阻断注册：提示商户登录后到实名认证页重试。
- 付费注册模式（`reg_pay=1`）下账号在支付成功后才创建，无法在注册时发起核身，本补丁在该模式下不强制；请让商户登录后到实名认证页自助认证。
- 修改过原版短信/实名逻辑的系统，覆盖前务必 diff，避免丢失你的二改内容。

## 七、技术支持

- 平台 API 文档：https://docs.starloft.cn/api
- 接口对齐：短信 `POST /v1/sms/send`；实名 `POST /v1/fv/auth`、`POST /v1/fv/result`、异步通知签名校验
- 作者：StarLoft