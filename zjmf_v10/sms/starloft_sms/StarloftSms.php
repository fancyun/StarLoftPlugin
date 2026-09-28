<?php
namespace sms\starloft_sms;

use sms\starloft_sms\logic\SmsSdk;

/**
 * StarLoft 短信服务(SMS)插件（智简魔方业务系统 v10 · 平台模板型）
 *
 * 按 v10「短信接口开发」规范实现（目录 public/plugins/sms/{目录名}）：
 *   - 命名空间 sms\{目录名}，入口类 {目录名大驼峰}，入口文件 {目录名大驼峰}.php
 *   - install() 返回 config/smsTemplate.php 模板数组，安装时自动创建全部系统模板
 *   - 模板操作：getCnTemplate / createCnTemplate / putCnTemplate / deleteCnTemplate
 *   - 发送：sendCnSms（支持方向：国内）
 *
 * 对接「星楼网络」短信服务(SMS)：
 *   - 模板创建/查询/修改/删除：/v1/sms/templates（平台模板型，模板经平台/上游审核）
 *   - 发送：/v1/sms/send（按平台模板 ID 发送，模板内容变量由上游按 {%变量%} 顺序替换）
 *
 * @author StarLoft
 * @version 3.0.0
 */
class StarloftSms
{
    /**
     * 插件基本信息
     */
    public $info = [
        'name'        => 'StarloftSms',
        'title'       => 'StarLoft 短信服务',
        'description' => 'StarLoft 短信服务（平台模板型 · 国内短信）— 智简魔方业务系统 v10',
        'status'      => 1,
        'author'      => 'StarLoft',
        'version'     => '3.0.0',
        'help_url'    => 'https://docs.starloft.cn/sms/plugin/starloft_sms',
    ];

    /**
     * 安装：返回模板数组，系统自动创建全部默认短信模板
     */
    public function install()
    {
        $file = __DIR__ . '/config/smsTemplate.php';
        if (is_file($file)) {
            $templates = include $file;
            if (is_array($templates)) {
                return $templates;
            }
        }
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    // ==================== 模板操作（国内） ====================

    /**
     * 获取国内模板状态
     * @param array $params ['template_id' => 模板ID, 'config' => 配置]
     */
    public function getCnTemplate($params)
    {
        try {
            $sdk        = new SmsSdk($this->getPluginConfig());
            $templateId = trim((string)($params['template_id'] ?? ''));
            if ($templateId === '') {
                return ['status' => 'error', 'msg' => '模板ID不能为空'];
            }
            $result = $sdk->getTemplate($templateId);
            if (!SmsSdk::isSuccess($result)) {
                return ['status' => 'error', 'msg' => (string)($result['message'] ?? '模板查询失败')];
            }
            $tpl = is_array($result['data']['template'] ?? null) ? $result['data']['template'] : [];
            return [
                'status'   => 'success',
                'template' => [
                    'template_id'     => (string)($tpl['template_id'] ?? $templateId),
                    'template_status' => (int)($tpl['template_status'] ?? 1),
                    'msg'             => (string)($tpl['msg'] ?? ''),
                ],
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'msg' => '系统错误: ' . $e->getMessage()];
        }
    }

    /**
     * 创建国内模板（@var(name) 占位转换为平台 {%name%} 占位）
     * @param array $params ['title' => 模板标题, 'content' => 模板内容, 'config' => 配置]
     */
    public function createCnTemplate($params)
    {
        try {
            $title   = trim((string)($params['title'] ?? ''));
            $content = trim((string)($params['content'] ?? ''));
            if ($title === '' || $content === '') {
                return ['status' => 'error', 'msg' => '模板标题与内容不能为空'];
            }
            $config = $this->getPluginConfig();
            $sdk    = new SmsSdk($config);
            // 优先使用模板传入的 sign_name，其次用全局配置；均留空时平台自动取该账号最新已通过的签名
            $signName = trim((string)($params['sign_name'] ?? ''));
            if ($signName === '') {
                $signName = trim((string)($config['sign_name'] ?? ''));
            }
            $result = $sdk->createTemplate([
                'title'     => $title,
                'content'   => $this->convertContent($content),
                'sms_type'  => (string)($config['sms_type'] ?? 'notify'),
                'sign_name' => $signName,
            ]);
            if (!SmsSdk::isSuccess($result)) {
                return ['status' => 'error', 'msg' => $this->describeSendError((string)($result['message'] ?? '模板创建失败'))];
            }
            $tpl = is_array($result['data']['template'] ?? null) ? $result['data']['template'] : [];
            return [
                'status'   => 'success',
                'template' => [
                    'template_id'     => (string)($tpl['template_id'] ?? ''),
                    'template_status' => (int)($tpl['template_status'] ?? 1),
                ],
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'msg' => '系统错误: ' . $e->getMessage()];
        }
    }

    /**
     * 修改国内模板（重置待审核并重新报备上游）
     * @param array $params ['template_id' => 模板ID, 'title' => 模板标题, 'content' => 模板内容, 'config' => 配置]
     */
    public function putCnTemplate($params)
    {
        try {
            $templateId = trim((string)($params['template_id'] ?? ''));
            $content    = trim((string)($params['content'] ?? ''));
            if ($templateId === '' || $content === '') {
                return ['status' => 'error', 'msg' => '模板ID与内容不能为空'];
            }
            $sdk    = new SmsSdk($this->getPluginConfig());
            $result = $sdk->updateTemplate($templateId, ['content' => $this->convertContent($content)]);
            if (!SmsSdk::isSuccess($result)) {
                return ['status' => 'error', 'msg' => (string)($result['message'] ?? '模板修改失败')];
            }
            $tpl = is_array($result['data']['template'] ?? null) ? $result['data']['template'] : [];
            return [
                'status'   => 'success',
                'template' => [
                    'template_id'     => (string)($tpl['template_id'] ?? $templateId),
                    'template_status' => (int)($tpl['template_status'] ?? 1),
                ],
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'msg' => '系统错误: ' . $e->getMessage()];
        }
    }

    /**
     * 删除国内模板
     * @param array $params ['template_id' => 模板ID, 'config' => 配置]
     */
    public function deleteCnTemplate($params)
    {
        try {
            $templateId = trim((string)($params['template_id'] ?? ''));
            if ($templateId === '') {
                return ['status' => 'error', 'msg' => '模板ID不能为空'];
            }
            $result = (new SmsSdk($this->getPluginConfig()))->deleteTemplate($templateId);
            if (!SmsSdk::isSuccess($result)) {
                return ['status' => 'error', 'msg' => (string)($result['message'] ?? '模板删除失败')];
            }
            return ['status' => 'success'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'msg' => '系统错误: ' . $e->getMessage()];
        }
    }

    // ==================== 发送短信（国内） ====================

    /**
     * 发送国内短信（平台模板型）
     * @param array $params ['mobile' => 手机号, 'content' => 模板内容, 'template_id' => 平台模板ID,
     *                       'templateParam' => 模板参数(按 @var 名映射), 'config' => 配置]
     */
    public function sendCnSms($params)
    {
        try {
            $config       = $this->getPluginConfig();
            $sdk          = new SmsSdk($config);
            $mobile       = trim((string)($params['mobile'] ?? ''));
            $content      = (string)($params['content'] ?? '');
            $templateId   = trim((string)($params['template_id'] ?? ''));
            $templateParam = is_array($params['templateParam'] ?? null) ? $params['templateParam'] : [];

            if ($mobile === '') {
                return ['status' => 'error', 'msg' => '手机号不能为空', 'content' => $content];
            }
            if ($templateId === '') {
                return ['status' => 'error', 'msg' => '模板ID不能为空', 'content' => $content];
            }

            $values = $this->orderTemplateParams($content, $templateParam);

            $result = $sdk->send([
                'phone_number_set' => $mobile,
                'template_id'      => $templateId,
                'template_params'  => $values,
                'sign_name'        => trim((string)($config['sign_name'] ?? '')),
                'sms_type'         => (string)($config['sms_type'] ?? 'notify'),
            ]);

            if (!SmsSdk::isSuccess($result)) {
                $msg = (string)($result['message'] ?? $result['result_message'] ?? '短信发送失败');
                return ['status' => 'error', 'msg' => $this->describeSendError($msg), 'content' => $content];
            }
            return ['status' => 'success', 'content' => $this->substituteContent($content, $values), 'msg' => ''];
        } catch (\Exception $e) {
            return ['status' => 'error', 'msg' => '系统错误: ' . $e->getMessage(), 'content' => $params['content'] ?? ''];
        }
    }

    /**
     * 兼容旧版「短信发送型」接口：模板型/直发型发送
     */
    public function sendSms($phones, $content = '', array $templateParams = [], $templateId = '', $smsType = '')
    {
        try {
            $config = $this->getPluginConfig();
            $sdk    = new SmsSdk($config);
            $params = ['phone_number_set' => $phones, 'sms_type' => $smsType];
            if ($content !== '') {
                $params['content'] = $content;
            } else {
                if ($templateId === '') {
                    $templateId = (string)($config['template_id'] ?? '');
                }
                if ($templateId === '') {
                    return ['code' => 400, 'message' => '未配置模板ID，请使用直发内容或配置默认模板'];
                }
                $params['template_id'] = $templateId;
                if (!empty($templateParams)) {
                    $params['template_params'] = array_values($templateParams);
                }
                if (empty($params['sign_name']) && !empty($config['sign_name'])) {
                    $params['sign_name'] = trim((string)$config['sign_name']);
                }
                if ($smsType === '' && !empty($config['sms_type'])) {
                    $params['sms_type'] = (string)$config['sms_type'];
                }
            }
            $result = $sdk->send($params);
            if (!SmsSdk::isSuccess($result)) {
                return ['code' => 1, 'message' => (string)($result['message'] ?? '短信发送失败'), 'data' => $result];
            }
            return [
                'code'    => 0,
                'message' => 'success',
                'data'    => [
                    'request_id'  => (string)($result['data']['request_id'] ?? ''),
                    'message_sid' => (string)($result['data']['message_sid'] ?? ''),
                ],
            ];
        } catch (\Exception $e) {
            return ['code' => 1, 'message' => '系统错误: ' . $e->getMessage()];
        }
    }

    // ==================== 工具方法 ====================

    /**
     * 按模板内容 @var(name) 出现顺序，将 templateParam 映射为平台顺序参数数组
     */
    protected function orderTemplateParams($content, array $templateParam)
    {
        $names = [];
        if (preg_match_all('/@var\(([^)]+)\)/', (string)$content, $m)) {
            $names = $m[1];
        }
        $values = [];
        foreach ($names as $n) {
            $values[] = isset($templateParam[$n]) ? (string)$templateParam[$n] : '';
        }
        return $values;
    }

    /**
     * 将魔方 @var(name) 占位转换为联麓要求的 {%name%} 占位（平台按占位符先后顺序替换参数）
     */
    protected function convertContent($content)
    {
        return preg_replace('/@var\(([^)]*)\)/', '{%$1%}', (string)$content);
    }

    /**
     * 将参数按序替换回模板内容（用于返回系统已渲染内容）
     */
    protected function substituteContent($content, array $values)
    {
        $i = 0;
        return preg_replace_callback('/@var\([^)]*\)/', function () use (&$i, $values) {
            $v = isset($values[$i]) ? $values[$i] : '';
            $i++;
            return $v;
        }, (string)$content);
    }

    protected function describeSendError($msg)
    {
        if (mb_strpos($msg, '余额') !== false || mb_strpos($msg, '额度') !== false) {
            return '短信服务余额/额度不足,请联系管理员充值后重试。(返回:' . $msg . ')';
        }
        if (mb_strpos($msg, '签名不存在') !== false || mb_strpos($msg, '尚未审核通过') !== false) {
            return $msg . '（注意：插件配置中的「默认短信签名内容」必须填星楼网络平台「短信服务 → 签名管理」里已审核通过的签名内容本身，不含【】，不是自拟的签名名称；留空可自动使用最新已通过的签名）';
        }
        if (mb_strpos($msg, '签名') !== false || mb_strpos($msg, '鉴权') !== false) {
            return '短信服务鉴权失败(AppKey/签名配置错误),请联系管理员检查插件配置。(返回:' . $msg . ')';
        }
        return '短信发送失败:' . $msg;
    }

    /**
     * 读取插件配置：优先取系统注入的 getConfig()（框架存在时），
     * 否则直接解析本目录 config.php 的默认值。
     */
    protected function getPluginConfig()
    {
        if (method_exists($this, 'getConfig')) {
            try {
                $c = $this->getConfig();
                if (is_array($c) && !empty($c)) {
                    return $c;
                }
            } catch (\Throwable $e) {}
        }

        $defaults = [];
        $cfgFile = __DIR__ . '/config.php';
        if (is_file($cfgFile)) {
            $arr = include $cfgFile;
            if (is_array($arr)) {
                foreach ($arr as $key => $item) {
                    if (is_array($item) && array_key_exists('value', $item)) {
                        $defaults[$key] = $item['value'];
                    }
                }
            }
        }
        return $defaults;
    }
}
