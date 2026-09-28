<?php
/*
 * 插件后台页面
 *
 * 系统在后台「插件」导航下生成的插件入口默认指向本文件；管理员登录已由魔方校验，
 * 这里不再校验插件自带的管理令牌，表单回发到当前地址、由 Admin::panel() 处理动作。
 */
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
echo \addon\starloft_fv_relay\logic\Admin::panel();