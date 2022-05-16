<?php

namespace App\Services\Parsers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Created by EchoCompany
 */
class Downloader
{
    protected $user_agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.109 Safari/537.36';

    const MAX_RETRIES = 3;

    /** depricated */
    /** @var Client */
    protected $client;

    protected $cookie = '';
    protected $download_delay = 5;
    protected $headers = [];
    public $last_log = '';

    public function __construct($headers = [])
    {
        // Автоповтор запросов
        $handlerStack = HandlerStack::create(new CurlHandler());
        $handlerStack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));

        $headers = $headers + ['User-Agent' => $this->user_agent];

        $this->client = new Client([
            'verify' => false,
            'timeout' => 60,
            'read_timeout' => 60,
            'headers' => $headers,
            'handler' => $handlerStack,
        ]);
    }

    /**
     * Проанализироватт как объеденить с конструктором чтобы не было дублей кода
     * @param array $headers
     */
    public function initCustomClient(array $headers)
    {
        $handlerStack = HandlerStack::create(new CurlHandler());
        $handlerStack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));

        $options = [
            'verify' => false,
            'timeout' => 60,
            'read_timeout' => 60,
            'headers' => [
                'User-Agent' => $this->user_agent
            ],
            'handler' => $handlerStack,
        ];

        $options['headers'] += $headers;

        $this->client = new Client($options);
    }

    public function getHeaders($header = '')
    {
        if (empty($header)) {
            return $this->headers;
        }

        if (!empty($this->headers[$header])) {
            return $this->headers[$header][0];
        }

        return [];
    }

    public function get($url)
    {
        $response = $this->client->get($url);
        $this->headers = $response->getHeaders();
        $content = $response->getBody()->getContents();

        // Площадка не возвращает 404 код
        if (Str::contains($content, 'Невозможно отобразить страницу')) {
            throw new \Exception('Страница не найдена: 404', 404);
        }

        return $content;
    }

    public function post($url, $params = [])
    {
        $response = $this->client->post($url, ['form_params' => $params]);
        $this->headers = $response->getHeaders();
        return $response->getBody()->getContents();
    }


    public function init_curl()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);


        if ($this->cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }
        return $ch;
    }


    /**
     * @param $url
     * @param int $recurse
     * @return bool|string|null
     * @deprecated
     */
    public function _download($url, $recurse = 3)
    {
        $start = time();
        try {
            //$error = ("Mets _download ChromeClient error :" . $err->getMessage());
            $ch = $this->init_curl();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');

            $data = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($data === FALSE) {
                //$error = curl_error($ch);
                $data = null;
                throw new \Exception('Error download ' . $url . ' log:' . curl_error($ch));
            }

            $ch = $this->init_curl();
            curl_close($ch);
        } catch (\Exception $err) {
            //$error = ("Mets _download Curl error :" . $err->getMessage());

            $default_options = [
                'timeout' => 120,
                'verify_peer' => false,
                'verify_host' => false,
                'headers' => [
                    'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36',
                ]
            ];
            $arguments = [
                '--window-size=1200,1100',
                '--headless',
                '--no-sandbox',
                '--remote-debugging-port=9222',
                '--disable-gpu',
                '--disable-crash-reporter',
                '--disable-extensions',
                '--disable-in-process-stack-traces',
                '--disable-logging',
                '--disable-dev-shm-usage',
                '--log-level=3',
            ];

            $client = \Symfony\Component\Panther\Client::createChromeClient(env('CHROMEDRIVER', null), $arguments, $default_options);
            try {
                $client->request('GET', $url);

                sleep(1);

                $page_crawler = $client->waitFor(mb_strpos($url, 'page') !== false ? '#searchRes' : '.ownName', 5, 750);

                $data = '<!DOCTYPE html>\n' . $page_crawler->html();
            } catch (\Exception $err) {
                $error = $err->getMessage();
                echo 'createChromeClient Error: ' . $error;
                $data = null;
            } finally {
                $client->quit();
            }
        }

        if (isset($error)) {
            Log::warning(" Error $error ");
            $this->last_log .= " Error $error ";

            if ($recurse > 0) {
                $this->last_log .= "-- Try again $recurse ";

                $sleep = rand(0, $this->download_delay);

                $this->last_log .= "-- delay $sleep <br>";
                sleep($sleep);

                return $this->_download($url, --$recurse);
            } else {
                echo('Error download ' . $url . ' log:' . $this->last_log);
                Log::channel('import_mets')->error('Error download ' . $url . ' log:' . $this->last_log);
                //throw new \Exception('Error download ' . $url . ' log:' . $this->last_log);
            }
        } else {
            $this->last_log .= ' ok [' . (time() - $start) . ']<br>';
        }

        return $data;
    }

    protected function retryDecider()
    {
        return function (
            $retries,
            Request $request,
            Response $response = null,
            RequestException $exception = null
        ) {

            if ($retries >= self::MAX_RETRIES) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                return true;
            }

            if ($response) {
                if ($response->getStatusCode() >= 500) {
                    return true;
                }
            }

            return false;
        };
    }

    protected function retryDelay()
    {
        return function ($numberOfRetries) {
            return 1000 * $numberOfRetries;
        };
    }
}
