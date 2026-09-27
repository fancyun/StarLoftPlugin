<?php
namespace addon\star_loft_api\controller;

use addon\star_loft_api\StarLoftApi;

/**
 * StarLoft API 中转插件 - 外部访问控制器（智简魔方业务系统 v10）
 *
 * 访问地址：
 *   - 中转端点: {域名}/{分类}/star_loft_api/index/apiRelay?endpoint=sms/send
 *   - 管理页:   {域名}/{分类}/star_loft_api/index/apiAdmin?token=xxx
 *
 * 中转为原始 HTTP 输出（原样回传平台响应），不走框架页面渲染。
 *
 * @author StarLoft
 * @version 1.0.0
 */
class IndexController
{
    /**
     * 中转入口：客户持站点下发的中转密钥调用
     */
    public function apiRelay()
    {
        (new StarLoftApi())->apiRelay();
    }

    /**
     * 管理页：站点管理员生成/停用客户密钥、查看调用日志
     */
    public function apiAdmin()
    {
        (new StarLoftApi())->apiAdmin();
    }
}