# 插件命名规则一致性检查报告

检查范围：`plugin/` 下 8 个插件（智简魔方 `zjmf_mfcw` / `zjmf_v10` 两代 × relay/certification/sms 三类）+ `epay` 覆盖式补丁。
检查维度：目录名、命名空间、类名、文件名、plugin.json 清单字段、版本号、冗余文件。

---

## 一、总体结论

两代插件**各自的命名体系内部自洽**，跨代的差异基本由各自框架规定，属正常。
发现 **1 处明确不一致（已修复）**，另 **3 项需确认**（未改动，见第三节）。

---

## 二、已修复：版本号不一致

两个 SMS 插件的版本号在 4 处出现、但值不一致（其他 6 个插件均一致）：

| 位置 | 修复前 | 修复后 |
|---|---|---|
| `plugin.json` `version` | 3.3.0 | 3.3.0 |
| `README.md` / `QUICK_START.md` | v3.3.0 | v3.3.0 |
| 主类 `$info['version']` | ❌ 3.2.0 | 3.3.0 |
| 主类 `@version` 文档头 | ❌ 3.2.0 | 3.3.0 |
| `logic/SmsSdk.php` `@version` | ❌ 3.2.0 | 3.3.0 |

成因：提交 `d65f2be`「版本 3.2.0 -> 3.3.0」只改了 `plugin.json`，漏改 PHP 里的 3 处。

修复文件（共 6 处）：
```
zjmf_mfcw/sms/starloftsms/StarloftsmsPlugin.php   (@version + $info['version'])
zjmf_mfcw/sms/starloftsms/logic/SmsSdk.php        (@version)
zjmf_v10/sms/starloft_sms/StarloftSms.php         (@version + $info['version'])
zjmf_v10/sms/starloft_sms/logic/SmsSdk.php        (@version)
```

修复后 8 个插件三项版本号全部一致，`php -l` 通过。

---

## 三、需确认项（未改动）

### 1. mfcw 内部目录命名风格不统一 —— 但疑似框架强制

| 插件 | mfcw 目录名 | 风格 |
|---|---|---|
| fv relay | `starloft_fv_relay` | 下划线 |
| sms relay | `starloft_sms_relay` | 下划线 |
| certification | `starloft_certification` | 下划线 |
| **sms** | **`starloftsms`** | **无下划线** |

提交 `795bc74` 明确记录这是**刻意改名**，原因：魔方 `sms` 类型路由（`zjmfhook`）要求
「**不含下划线的小写目录 ↔ 首字母大写类名**」，原 `starloft_sms/StarloftSmsPlugin` 会导致
插件类无法加载。故 `starloftsms` / `StarloftsmsPlugin` 是正确写法。

> ⚠️ 待确认：同一代的 `addons` / `certification` 类型是否也有同样约束？
> 这两个类型的 README 把外部路由标注为「待确认」，若同样受限则目录名也需去掉下划线。

### 2. 跨代同一插件的目录名不同 —— 潜在风险

- mfcw：`starloftsms`（无下划线，符合 zjmfhook 规则）
- v10：`starloft_sms`（**有**下划线）

v10 的 README/QUICK_START 只写「目录名必须与命名空间/类名一致」，未提「不含下划线」。
**若 v10 的 `sms` 路由沿用同一套 zjmfhook 规则，v10 侧会加载失败。** 建议在 v10 环境实测安装确认；
若确需改，须连同目录、`plugin.json` 的 `dir`/`namespace`/`class`、类名一并改（改动面较大，故未擅自处理）。

### 3. 疑似冗余文件

| 文件 | 情况 | 影响 |
|---|---|---|
| 4 个 relay 插件的 `logic/sdk/Client.php` | 全仓库**无任何 require/use 引用**（relay 自己用 `Relay::forward()` 签名转发） | 死代码；且它也曾含 `"\\n"` 签名 bug，是重复维护风险 |
| v10 两个 relay 插件的 `lang/zh-cn.php` | 代码中无 `Lang::` / `lang()` 调用 | 可能由框架自动加载，需确认后再决定去留 |

> 注：certification / sms 插件的 `logic/sdk/` 是**真在用**的（`FvSdk.php` / `SmsSdk.php` 显式 `require_once`）。

---

## 四、确认正常（无需处理）

| 维度 | 说明 |
|---|---|
| 命名空间 `StarLoft\Sdk` vs 物理路径 `logic/sdk/` | 不一致，但通过显式 `require_once` 加载、非 PSR-4 自动加载；12 个文件写法完全统一，属品牌命名空间，可接受 |
| 插件根目录 `addons/`（mfcw）vs `addon/`（v10） | 与 `plugin_type` 字段一一对应，框架规定 |
| 类名后缀 `XxxPlugin`（mfcw）vs `Xxx`（v10） | 两代各自**内部完全一致**，框架规定 |
| `menu.php`（mfcw）vs `template/admin/index.php`（v10） | 后台入口机制不同，框架规定 |
| `requires.zjmf_version` 仅 mfcw 有 | 跨代清单字段差异，v10 是否支持该键待查 |
| 文件名 ↔ 类名 | 8 个插件全部一一对应（PascalCase），无错配 |
| `$info['name']` | 均为「目录名的驼峰形式」（mfcw 再带 `Plugin` 后缀时类名另算），两代各自自洽：`starloft_fv_relay`→`StarloftFvRelay`、`starloftsms`→`Starloftsms` |
| `plugin.json` 的 `dir`/`namespace`/`class` | 8/8 与磁盘实际目录、命名空间、类名完全匹配 |

---

## 五、建议

1. （已做）统一 SMS 插件版本号。
2. 在 **v10 环境实测** `sms` 插件能否正常加载 —— 若不能，按 zjmfhook 规则改为 `starloftsms`。
3. 确认 `addons` / `certification` 类型是否也受「无下划线」约束。
4. 确认后清理冗余文件（relay 的 `logic/sdk/Client.php`、v10 relay 的 `lang/zh-cn.php`）。
5. 建议定一条发布检查清单：`plugin.json.version` ↔ `$info['version']` ↔ `@version` 三处必须同步。
