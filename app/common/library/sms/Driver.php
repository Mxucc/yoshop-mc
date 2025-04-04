<?php
// +----------------------------------------------------------------------
// | 萤火商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2017~2024 https://www.yiovo.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed 这不是一个自由软件，不允许对程序代码以任何形式任何目的的再发行
// +----------------------------------------------------------------------
// | Author: 萤火科技 <admin@yiovo.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\library\sms;

use app\common\library\helper;
use cores\traits\ErrorTrait;
use cores\exception\BaseException;
use Overtrue\EasySms\EasySms;
use Overtrue\EasySms\Exceptions\InvalidArgumentException;
use Overtrue\EasySms\Exceptions\NoGatewayAvailableException;

use Overtrue\EasySms\Message;
use Overtrue\EasySms\Exceptions\GatewayErrorException;
use GuzzleHttp\Client;
/**
 * 短信通知模块驱动
 * Class Driver
 * @package app\common\library\sms
 */
class Driver
{
    use ErrorTrait;

    // 后台短信配置信息
    private $smsConfig;

    // 当前短信平台 (后台设置)
    private $gateway;

    /**
     * 构造方法
     * Driver constructor.
     * @param array $smsConfig
     */
    public function __construct(array $smsConfig)
    {
        // 配置信息
        $this->smsConfig = $smsConfig;
        // 当前短信平台
        $this->gateway = $this->smsConfig['default'];
    }

    /**
     * 发送短信通知
     * @param string $acceptPhone 接收的手机号
     * @param string $templateCode 短信模板ID
     * @param array $templateParams 短信模板参数
     * @return bool
     * @throws BaseException
     * @throws InvalidArgumentException
     */
    public function sendSms(string $acceptPhone, string $templateCode, array $templateParams): bool
    {
        // 实例化EasySms
        $easySmsConfig = Config::getEasySmsConfig($this->smsConfig);
        $easySms = new EasySms($easySmsConfig);
        $easySms->extend('fsidc', function($gatewayConfig){
            return new FSGateway($gatewayConfig);
        });
        try {
            // 执行发送短信
            $result = $easySms->send($acceptPhone, [
                'template' => $templateCode,
                'data' => $this->getSmsTemplateData($templateParams),
            ]);
            // 短信发送成功的错误处理
            $sendStatus = $this->resultHandle($result);
        } catch (NoGatewayAvailableException $e) {
            // 短信发送异常的错误处理
            $sendStatus = false;
            $this->exceptionHandle($e);
        }
        // 记录日志
        helper::logInfo('发送短信', [
            'gateway' => $this->smsConfig['default'],
            'acceptPhone' => $acceptPhone,
            'templateCode' => $templateCode,
            'templateParams' => $templateParams,
            'sendStatus' => $sendStatus ? 'true' : 'false',
            'sendErrMsg' => $this->getError(),
        ]);
        // 存在异常时抛错
        $sendStatus === false && throwError($this->getError());
        return $sendStatus;
    }

    /**
     * 短信发送成功的错误处理
     * @param array $result
     * @return bool
     */
    private function resultHandle(array $result): bool
    {
        // 腾讯云短信错误: 模板ID不正确
        if ($this->gateway === 'qcloud') {
            $response = $result[$this->gateway]['result']['Response']['SendStatusSet'];
            if (isset($response[0]) && isset($response[0]['Code']) && $response[0]['Code'] !== 'Ok') {
                $this->error = '请检查后台短信平台参数和模板ID是否正确';
                return false;
            }
        }
        return true;
    }

    /**
     * 短信发送异常的错误处理
     * @param NoGatewayAvailableException $e
     */
    private function exceptionHandle(NoGatewayAvailableException $e)
    {
        // 短信发送失败
        $err = $e->getLastException();
        if ($err instanceof \GuzzleHttp\Exception\ClientException) {
            $body = (string)$err->getResponse()->getBody();
            $result = helper::jsonDecode($body);
            if (isset($result['Message'])) {
                // $errMsg = $result['Message'];
                $this->error = '请检查后台短信平台的参数设置';
                return;
            }
            if (isset($result['message'])) {
                $this->error = $result['message'];
                return;
            }
        }
//        if ($err instanceof \Overtrue\EasySms\Exceptions\GatewayErrorException) {
//            $this->error = $err->getMessage();
//            return;
//        }
        $this->error = $err->getMessage();
    }

    /**
     * 生成短信模板数据
     * @param array $templateParams
     * @return array
     */
    private function getSmsTemplateData(array $templateParams): array
    {
        if ($this->gateway === 'qcloud') {
            $templateParams = array_values($templateParams);
        }
        return $templateParams;
    }
}

use Overtrue\EasySms\Traits\HasHttpRequest;
use Overtrue\EasySms\Contracts\MessageInterface;
use Overtrue\EasySms\Contracts\PhoneNumberInterface;

class FSGateway
{
    use HasHttpRequest;

    public const ENDPOINT_URL = 'https://service-1o4d82wx-1300755093.bj.apigw.tencentcs.com/release/sms/send';
    public const SIGNATURE_ALGORITHM = 'hmacSHA1';
    public const SUCCESS_CODE = '000000';

    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getName()
    {
        return 'fsidc';
    }
    
    public function send(PhoneNumberInterface $phone, MessageInterface $message, array $params = [])
    {
        $response = $this->post(self::ENDPOINT_URL, [
            'headers' => $this->buildHeaders(),
            'form_params' => $this->buildBodyParams($phone, $message),
        ]);

        if (self::SUCCESS_CODE !== $response['Code']) {
            throw new GatewayErrorException(
                $response['Message'] ?? 'Unknown error',
                $response['Code'],
                ['gateway' => get_class($this), 'config' => $this->config]
            );
        }

        return $response;
    }

    private function buildHeaders(): array
    {
        $datetime = gmdate('D, d M Y H:i:s T');
        $signStr = "x-date: {$datetime}\nx-source: market";
        $sign = base64_encode(hash_hmac('sha1', $signStr, $this->config['secretKey'], true));
        $auth = sprintf('hmac id="%s", algorithm="%s", headers="x-date x-source", signature="%s"', 
            $this->config['secretId'], self::SIGNATURE_ALGORITHM, $sign);

        return [
            'X-Source' => 'market',
            'X-Date' => $datetime,
            'Authorization' => $auth,
        ];
    }

    private function buildBodyParams(PhoneNumberInterface $phone, MessageInterface $message): array
    {
        return [
            'content' => $message->getContent(),
            'mobile' => $phone->getUniversalNumber(),
        ];
    }
}