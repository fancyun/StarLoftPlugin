<?php
/**
 * StarLoft API 中转插件配置项（智简魔方财务版）
 *
 * 此文件定义插件的配置项，系统会自动生成配置表单。
 * 站点把 StarLoft 的短信/人脸核验 API 转售给自有客户时，在此填写站点的平台密钥与管理令牌。
 */
return [
    'api_url' => [
        'title' => '平台API地址',
        'type'  => 'text',
        'value' => 'https://api.starloft.cn',
        'tip'   => '星楼网络平台的API地址，例如：https://api.starloft.cn',
    ],

    'api_key' => [
        'title' => '平台API Key',
        'type'  => 'text',
        'value' => '',
        'tip'   => '本站点在星楼网络平台的API密钥（用户中心 → API密钥管理）；客户调用会消耗该账号的余额/资源包',
    ],

    'api_secret' => [
        'title' => '平台API Secret',
        'type'  => 'text',
        'value' => '',
        'tip'   => '与平台API Key配对的Secret（用于HMAC签名），请妥善保管',
    ],

    'admin_token' => [
        'title' => '管理令牌',
        'type'  => 'text',
        'value' => '',
        'tip'   => '访问管理页（生成/停用客户密钥、查看日志）与执行管理操作的口令；留空时管理页不可访问',
    ],

    'enable_sms' => [
        'title' => '短信中转',
        'type'  => 'radio',
        'options' => [
            '1' => '启用',
            '0' => '关闭',
        ],
        'value' => '1',
        'tip'   => '关闭后所有 sms/* 端点拒绝中转',
    ],

    'enable_fv' => [
        'title' => '人脸中转',
        'type'  => 'radio',
        'options' => [
            '1' => '启用',
            '0' => '关闭',
        ],
        'value' => '1',
        'tip'   => '关闭后所有 fv/* 端点拒绝中转',
    ],

    'endpoint_whitelist' => [
        'title' => '端点白名单',
        'type'  => 'text',
        'value' => '',
        'tip'   => '留空表示全部端点可中转；多个用英文逗号分隔，如：sms/send,fv/auth',
    ],

    'daily_quota' => [
        'title' => '单客户日调用上限',
        'type'  => 'text',
        'value' => '0',
        'tip'   => '每个客户密钥每天最多调用多少次，0 表示不限；超限返回 429',
    ],

    // ==================== 计费（客户预付余额按次扣费） ====================

    'price_sms_send' => [
        'title' => '短信发送单价（元/号码）',
        'type'  => 'text',
        'value' => '0',
        'tip'   => '每调用一次短信发送、按请求里的号码个数计费；长短信拆分条数不另计，请按最坏情况定价。0 表示免费',
    ],

    'price_fv_auth' => [
        'title' => '有源人脸单价（元/次）',
        'type'  => 'text',
        'value' => '0',
        'tip'   => 'fv/auth（有源人脸核验）每次调用扣费金额，0 表示免费',
    ],

    'price_fv_self' => [
        'title' => '无源人脸单价（元/次）',
        'type'  => 'text',
        'value' => '0',
        'tip'   => 'fv/self（无源人脸核验）每次调用扣费金额，0 表示免费',
    ],

    'price_other' => [
        'title' => '其余端点单价（元/次）',
        'type'  => 'text',
        'value' => '0',
        'tip'   => '签名/模板/回执/上行/结果查询/活体图等其余端点每次调用扣费金额，0 表示免费',
    ],

    'log_enabled' => [
        'title' => '记录调用日志',
        'type'  => 'radio',
        'options' => [
            '1' => '启用',
            '0' => '关闭',
        ],
        'value' => '1',
        'tip'   => '是否记录每次中转调用（客户、端点、结果、耗时、IP），用于给客户对账',
    ],
];