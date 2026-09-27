<?php
namespace addons\starloft_fv_relay\controller;

use addons\starloft_fv_relay\StarloftFvRelayPlugin;
use addons\starloft_fv_relay\logic\Admin;

/**
 * 后台管理面板控制器（魔方后台插件菜单的入口）
 *
 * 访问地址由 menu.php 生成：/admin/addons?_plugin=starloft_fv_relay&_controller=index&_action=console
 * 管理员登录由魔方后台保证，此处不再校验插件自带的管理令牌。
 */
class IndexController extends \app\admin\controller\PluginAdminBaseController
{
    /**
     * 输出管理面板（客户密钥、余额、调用日志；表单回发到本地址）
     */
    public function console()
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo Admin::panel((new StarloftFvRelayPlugin())->pluginConfig());
    }
}