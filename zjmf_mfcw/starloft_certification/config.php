<?php
/**
 * StarLoft 人脸核验(FV)插件（智简魔方财务版 · 人脸识别型 _fv）
 *
 * 此文件定义插件的配置项，系统会自动生成配置表单。
 * 对接「星楼网络」人脸核验(FV)服务，API 域名 api.starloft.cn。
 */
return [
    // ==================== 系统字段 ====================
    // 以下两个字段为系统必需字段

    'amount' => [
        'title' => '单次认证费用',
        'type'  => 'text',
        'value' => '0',
        'tip'   => '每次认证扣除的费用（元），0表示不扣费',
    ],

    'free' => [
        'title' => '免费认证次数',
        'type'  => 'text',
        'value' => '0',
        'tip'   => '每个用户的免费认证次数，0表示无免费次数',
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

    'service' => [
        'title' => '人脸核验体服务标识(service)',
        'type'  => 'text',
        'value' => 'fv_auth',
        'tip'   => '平台人脸核验子产品服务标识。默认为 fv_auth（有源人脸，身份证参考照片比对）。',
    ],

    'skip_notify' => [
        'title' => '跳过平台异步通知',
        'type'  => 'radio',
        'options' => [
            '1' => '启用（只靠主动查询/校对同步结果）',
            '0' => '禁用（启用平台异步通知）',
        ],
        'value' => '0',
        'tip'   => '启用后创建订单时不回传 notify_url，结果完全由插件主动查询与结果校对同步',
    ],

    'require_reconcile' => [
        'title' => '落地前结果校对',
        'type'  => 'radio',
        'options' => [
            '1' => '启用（收到结果后主动调用结果校对接口对齐上游）',
            '0' => '禁用',
        ],
        'value' => '1',
        'tip'   => '在落地本地状态前主动调用 /api/fv/result 校对最终状态，保证与平台一致',
    ],
];