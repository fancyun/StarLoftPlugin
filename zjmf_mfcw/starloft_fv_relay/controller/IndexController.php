<?php
namespace addons\starloft_fv_relay\controller;

use addons\starloft_fv_relay\StarloftFvRelayPlugin;
use addons\starloft_fv_relay\logic\Admin;

/**
 * 后台管理面板控制器（魔方后台插件菜单的入口）
 *
 * 地址由 menu.php 生成：/admin/addons?_plugin=starloft_fv_relay&_controller=index&_action=console
 * 不继承框架基类：不同魔方版本的基类命名空间不一致，继承会让缺类变成致命错误把页面打成 500。
 */
class IndexController
{
    /**
     * 输出管理面板（客户密钥、余额、调用日志；表单回发到本地址）
     */
    public function console()
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        try {
            echo Admin::panel((new StarloftFvRelayPlugin())->pluginConfig());
        } catch (\Throwable $e) {
            echo '<div style="font-family:sans-serif;padding:16px;color:#e34d59;">面板渲染失败：'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }
}