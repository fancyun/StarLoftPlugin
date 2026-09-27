<?php
namespace certification\starloft_api;

use certification\starloft_api\logic\Admin;
use certification\starloft_api\logic\KeyStore;
use certification\starloft_api\logic\Relay;

/**
 * StarLoft API 中转插件（智简魔方业务系统 v10）
 *
 * 面向「站点把 StarLoft 的短信/人脸核验 API 转售给自有客户」：站点管理员在本插件的管理页
 * 为客户生成一对中转密钥，客户持密钥按平台同一套规则签名调用本站点的中转端点，插件校验后
 * 用站点配置的平台密钥转发到 StarLoft，并原样回传响应、按客户记录调用日志。
 *
 * 端点（v10 走 controller/IndexController.php 暴露）：
 *   - 中转：/{分类}/starloft_api/index/apiRelay?endpoint=sms/send
 *   - 管理：/{分类}/starloft_api/index/apiAdmin?token=xxx
 *
 * @author StarLoft
 * @version 1.0.0
 */
class StarloftApi
{
    /**
     * 插件基本信息
     */
    public $info = [
        'name'        => 'StarloftApi',
        'title'       => 'StarLoft API 中转',
        'description' => '把 StarLoft 短信/人脸核验 API 转售给站点客户：客户独立密钥、站点中转、调用日志 — 智简魔方业务系统 v10',
        'status'      => 1,
        'author'      => 'StarLoft',
        'version'     => '1.0.0',
        'help_url'    => 'https://docs.starloft.cn/relay/plugin/starloft_api',
    ];

    /**
     * 安装：创建客户密钥表与调用日志表（幂等）
     *
     * 建表失败不阻断安装（中转仍可用，管理页会给出可手工执行的建表 SQL）。
     */
    public function install()
    {
        $error = null;
        if (!KeyStore::ensureTables($error) && function_exists('trace')) {
            trace('StarloftApi 建表失败: ' . (string)$error, 'error');
        }
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    /**
     * 中转入口（客户调用，由控制器转调）：/{分类}/starloft_api/index/apiRelay?endpoint=sms/send
     */
    public function apiRelay()
    {
        Relay::handle($this->getPluginConfig());
    }

    /**
     * 管理页（站点管理员使用，用配置里的管理令牌保护，由控制器转调）
     */
    public function apiAdmin()
    {
        Admin::render($this->getPluginConfig());
    }

    /**
     * 实名认证接口占位：本插件挂在实名认证分类下只为被魔方识别，不提供实名核验能力
     */
    public function StarloftApiCollectionInfo($type)
    {
        return [];
    }

    public function StarloftApiPerson($certifi)
    {
        return $this->notCertificationProvider();
    }

    public function StarloftApiCompany($certifi)
    {
        return $this->notCertificationProvider();
    }

    protected function notCertificationProvider()
    {
        return '<h3 class="pt-2 font-weight-bold h2 py-4" style="color:#f56c6c;">'
            . '本插件用于 API 中转，请勿在实名认证设置中选择本插件</h3>';
    }

    /**
     * 读取插件配置：优先取系统注入的 getConfig()（框架存在时），否则解析本目录 config.php 的默认值
     */
    protected function getPluginConfig()
    {
        if (method_exists($this, 'getConfig')) {
            try {
                $c = $this->getConfig();
                if (is_array($c) && !empty($c)) {
                    return $c;
                }
            } catch (\Throwable $_) {}
        }

        $defaults = [];
        $file     = __DIR__ . '/config.php';
        if (is_file($file)) {
            $arr = include $file;
            if (is_array($arr)) {
                foreach ($arr as $k => $v) {
                    if (is_array($v)) {
                        $defaults[$k] = $v['value'] ?? ($v['default'] ?? '');
                    } elseif ($v !== null) {
                        $defaults[$k] = $v;
                    }
                }
            }
        }
        return $defaults;
    }
}