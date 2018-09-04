<?php

namespace src\Client;

use AppController;
use src\Api\Exceptions\WrongRequestException;
use src\Api\Interfaces\GetRequestInterface;
use src\Api\Interfaces\RequestInterface;
use src\Exceptions\RequestException;
use src\Helpers\Log;
use src\Helpers\Logger;
use src\Interfaces\ClientInterface;
use src\Api\Interfaces\PostRequestInterface;

class Client implements ClientInterface
{

    const HTTP_OK = 200;
    const HTTP_CREATED = 201;
    const HTTP_ACCEPTED = 202;
    const HTTP_NO_CONTENT = 204;
    const HTTP_SEE_OTHER = 303;

    /**
     * Успешные коды ответов
     */
    private $successCodes = [
        self::HTTP_OK,
        self::HTTP_CREATED,
        self::HTTP_ACCEPTED,
        self::HTTP_NO_CONTENT,
    ];

    const CONTENT_TYPE = 'Content-Type: application/json; charset=utf-8';
    const AUTHORIZATION = 'Authorization: Bearer ';
    const REQUEST_ID = 'X-Request-ID: ';

    /**
     * http-заголовки
     *
     * @var array
     */
    private $headers = [];

    /**
     * Приватный ключ для доступа к API
     *
     * @var string
     */
    private $apiKey;

    /**
     * Id магазина
     *
     * @var string
     */
    private $shopId;

    /**
     * @var string
     */
    private $url;

    /**
     * @param string $apiKey
     * @param string $shopId
     * @param string $url
     */
    public function __construct($apiKey, $shopId, $url)
    {
        $this->apiKey = $apiKey;
        $this->shopId = $shopId;
        $this->url = $url;
        $this->headers = [
            self::CONTENT_TYPE,
            self::AUTHORIZATION . $this->apiKey,
            self::REQUEST_ID . $this->shopId,
        ];
    }

    /**
     * @param RequestInterface $request
     * @param string           $method
     *
     * @return string
     * @throws RequestException
     * @throws WrongRequestException
     */
    public function sendRequest(RequestInterface $request, $method)
    {
        $params = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->headers,
        ];

        if (ClientInterface::HTTP_METHOD_GET === $method) {
            if (!($request instanceof GetRequestInterface)) {
                throw new WrongRequestException(__d(RBK_MONEY_MODULE, 'RBK_MONEY_WRONG_VALUE') . ' Request');
            }
        } elseif (ClientInterface::HTTP_METHOD_POST === $method) {
            if (!($request instanceof PostRequestInterface)) {
                throw new WrongRequestException(__d(RBK_MONEY_MODULE, 'RBK_MONEY_WRONG_VALUE') . ' Request');
            }

            $params[CURLOPT_POSTFIELDS] = json_encode($request->toArray());
        }

        return $this->sendCurl($this->url . $request->getPath(), $params);
    }

    /**
     * @param string $url
     * @param array  $options
     *
     * @return string
     *
     * @throws RequestException
     */
    private function sendCurl($url, array $options)
    {
        $ch = curl_init($url);

        $headers = '';

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header_line) use (&$headers) {
            $headers .= trim($header_line);

            return strlen($header_line);
        });

        curl_setopt_array($ch, $options);

        $result = curl_exec($ch);

        $responseInfo = curl_getinfo($ch);

        $model = new AppController();
        $model->uses = [
            'ModuleRbkmoneySetting'
        ];
        $saveLogs = $model->ModuleRbkmoneySetting->find('first', ['conditions' => ['code' => 'saveLogs']]);

        curl_close($ch);

        if (__d(RBK_MONEY_MODULE, 'RBK_MONEY_SHOW_PARAMETER') === $saveLogs['ModuleRbkmoneySetting']['value']) {
            $log = new Log(
                $url,
                $options[CURLOPT_CUSTOMREQUEST],
                json_encode($options[CURLOPT_HTTPHEADER], 256),
                $result,
                $headers
            );

            if (isset($options[CURLOPT_POSTFIELDS])) {
                $log = $log->setRequestBody($options[CURLOPT_POSTFIELDS]);
            }

            $logger = new Logger();
            $logger->saveLog($log);
        }

        if (self::HTTP_SEE_OTHER === $responseInfo['http_code']) {
            return $responseInfo['location'];
        }

        if (false === $result) {
            throw new RequestException(__d(RBK_MONEY_MODULE, 'RBK_MONEY_RESPONSE_NOT_RECEIVED'));
        } elseif (!in_array($responseInfo['http_code'], $this->successCodes)) {
            throw new RequestException($result, $responseInfo['http_code']);
        }

        return $result;
    }

}
