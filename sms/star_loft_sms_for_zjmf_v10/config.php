<?php
/**
 * StarLoft 短信服务(SMS)插件（智简魔方业务系统 v10 · 平台模板型）
 *
 * 此文件定义插件的配置项，系统会自动生成配置表单（后台：短信设置 → 接口管理 → 配置）。
 * 对接「星楼网络」短信服务(SMS)，API 域名含 www.starloft.cn / service.starloft.cn。
 */
return [
    // ==================== 系统字段 ====================

    'amount' => [
        'title' => '单条短信费用',
        'type'  => 'text',
        'value' => '0',
        'tip'   => '每条短信扣除的费用（元），0表示不扣费',
    ],

    'free' => [
        'title' => '免费短信条数',
        'type'  => 'text',
        'value' => '0',
        'tip'   => '每个用户的免费短信条数，0表示无免费条数',
    ],

    // ==================== 插件配置字段 ====================

    'api_url' => [
        'title' => 'API地址',
        'type'  => 'text',
        'value' => 'https://api.starloft.cn',
        'tip'   => '星楼网络平台的API地址，例如：https://api.starloft.cn',
    ],

    'api_key' => [
        'title' => 'API Key',
        'type'  => 'text',
        'value' => '',
        'tip'   => '在星楼网络平台后台「用户中心 → API密钥管理」获取',
    ],

    'api_secret' => [
        'title' => 'API Secret',
        'type'  => 'text',
        'value' => '',
        'tip'   => '在星楼网络平台后台获取（用于生成HMAC签名），请妥善保管',
    ],

    'sign_name' => [
        'title' => '默认短信签名内容',
        'type'  => 'text',
        'value' => '',
        'tip'   => '填星楼网络平台「短信服务 → 签名管理」中已审核通过的签名内容本身（不含【】，如：星楼网络），不是自拟的签名名称；留空则自动使用该账号最新一条已通过的签名',
    ],

    'template_id' => [
        'title' => '默认模板ID',
        'type'  => 'text',
        'value' => '',
        'tip'   => '平台已审核通过的短信模板ID；模板发送时使用',
    ],

    'sms_type' => [
        'title' => '默认短信类型',
        'type'  => 'select',
        'options' => [
            'verify'    => '验证码短信',
            'notify'    => '通知短信',
        ],
        'value' => 'notify',
        'tip'   => '模板发送时的默认短信类型（暂不支持营销短信）',
    ],
];
