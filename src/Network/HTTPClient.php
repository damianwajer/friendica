<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
 *
 * @license GNU APGL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Network;

use DOMDocument;
use DomXPath;
use Friendica\Core\Config\IConfig;
use Friendica\Core\System;
use Friendica\Util\Network;
use Friendica\Util\Profiler;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Performs HTTP requests to a given URL
 */
class HTTPClient implements IHTTPClient
{
	/** @var LoggerInterface */
	private $logger;
	/** @var Profiler */
	private $profiler;
	/** @var IConfig */
	private $config;
	/** @var string */
	private $userAgent;
	/** @var Client */
	private $client;

	public function __construct(LoggerInterface $logger, Profiler $profiler, IConfig $config, string $userAgent, Client $client)
	{
		$this->logger    = $logger;
		$this->profiler  = $profiler;
		$this->config    = $config;
		$this->userAgent = $userAgent;
		$this->client    = $client;
	}

	/**
	 * @throws HTTPException\InternalServerErrorException
	 */
	protected function request(string $method, string $url, array $opts = []): IHTTPResult
	{
		$this->profiler->startRecording('network');
		$this->logger->debug('Request start.', ['url' => $url, 'method' => $method]);

		if (Network::isLocalLink($url)) {
			$this->logger->info('Local link', ['url' => $url, 'callstack' => System::callstack(20)]);
		}

		if (strlen($url) > 1000) {
			$this->logger->debug('URL is longer than 1000 characters.', ['url' => $url, 'callstack' => System::callstack(20)]);
			$this->profiler->stopRecording();
			return CurlResult::createErrorCurl(substr($url, 0, 200));
		}

		$parts2     = [];
		$parts      = parse_url($url);
		$path_parts = explode('/', $parts['path'] ?? '');
		foreach ($path_parts as $part) {
			if (strlen($part) <> mb_strlen($part)) {
				$parts2[] = rawurlencode($part);
			} else {
				$parts2[] = $part;
			}
		}
		$parts['path'] = implode('/', $parts2);
		$url           = Network::unparseURL($parts);

		if (Network::isUrlBlocked($url)) {
			$this->logger->info('Domain is blocked.', ['url' => $url]);
			$this->profiler->stopRecording();
			return CurlResult::createErrorCurl($url);
		}

		$conf = [];

		if (!empty($opts['cookiejar'])) {
			$jar                           = new FileCookieJar($opts['cookiejar']);
			$conf[RequestOptions::COOKIES] = $jar;
		}

		$header = [];

		if (!empty($opts['accept_content'])) {
			array_push($header, 'Accept: ' . $opts['accept_content']);
		}

		if (!empty($opts['header'])) {
			$header = array_merge($opts['header'], $header);
		}

		if (!empty($opts['headers'])) {
			$this->logger->notice('Wrong option \'headers\' used.');
			$header = array_merge($opts['headers'], $header);
		}

		$conf[RequestOptions::HEADERS] = array_merge($this->client->getConfig(RequestOptions::HEADERS), $header);

		if (!empty($opts['timeout'])) {
			$conf[RequestOptions::TIMEOUT] = $opts['timeout'];
		}

		$conf[RequestOptions::ON_HEADERS] = function (ResponseInterface $response) use ($opts) {
			if (!empty($opts['content_length']) &&
				$response->getHeaderLine('Content-Length') > $opts['content_length']) {
				throw new TransferException('The file is too big!');
			}
		};

		try {
			switch ($method) {
				case 'get':
					$response = $this->client->get($url, $conf);
					break;
				case 'head':
					$response = $this->client->head($url, $conf);
					break;
				default:
					throw new TransferException('Invalid method');
			}
			return new GuzzleResponse($response, $url);
		} catch (TransferException $exception) {
			if ($exception instanceof RequestException &&
				$exception->hasResponse()) {
				return new GuzzleResponse($exception->getResponse(), $url, $exception->getCode(), '');
			} else {
				return new CurlResult($url, '', ['http_code' => $exception->getCode()], $exception->getCode(), '');
			}
		} finally {
			$this->logger->debug('Request stop.', ['url' => $url, 'method' => $method]);
			$this->profiler->stopRecording();
		}
	}

	/** {@inheritDoc}
	 *
	 * @throws HTTPException\InternalServerErrorException
	 */
	public function head(string $url, array $opts = []): IHTTPResult
	{
		return $this->request('head', $url, $opts);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(string $url, array $opts = []): IHTTPResult
	{
		return $this->request('get', $url, $opts);
	}

	/**
	 * {@inheritDoc}
	 */
	public function post(string $url, $params, array $headers = [], int $timeout = 0): IHTTPResult
	{
		$opts = [];

		$opts[RequestOptions::JSON] = $params;

		if (!empty($headers)) {
			$opts['headers'] = $headers;
		}

		if (!empty($timeout)) {
			$opts[RequestOptions::TIMEOUT] = $timeout;
		}

		return $this->request('post', $url, $opts);
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalUrl(string $url, int $depth = 1, bool $fetchbody = false)
	{
		if (Network::isLocalLink($url)) {
			$this->logger->info('Local link', ['url' => $url, 'callstack' => System::callstack(20)]);
		}

		if (Network::isUrlBlocked($url)) {
			$this->logger->info('Domain is blocked.', ['url' => $url]);
			return $url;
		}

		if (Network::isRedirectBlocked($url)) {
			$this->logger->info('Domain should not be redirected.', ['url' => $url]);
			return $url;
		}

		$url = Network::stripTrackingQueryParams($url);

		if ($depth > 10) {
			return $url;
		}

		$url = trim($url, "'");

		$this->profiler->startRecording('network');

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_NOBODY, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);

		curl_exec($ch);
		$curl_info = @curl_getinfo($ch);
		$http_code = $curl_info['http_code'];
		curl_close($ch);

		$this->profiler->stopRecording();

		if ($http_code == 0) {
			return $url;
		}

		if (in_array($http_code, ['301', '302'])) {
			if (!empty($curl_info['redirect_url'])) {
				return $this->finalUrl($curl_info['redirect_url'], ++$depth, $fetchbody);
			} elseif (!empty($curl_info['location'])) {
				return $this->finalUrl($curl_info['location'], ++$depth, $fetchbody);
			}
		}

		// Check for redirects in the meta elements of the body if there are no redirects in the header.
		if (!$fetchbody) {
			return $this->finalUrl($url, ++$depth, true);
		}

		// if the file is too large then exit
		if ($curl_info["download_content_length"] > 1000000) {
			return $url;
		}

		// if it isn't a HTML file then exit
		if (!empty($curl_info["content_type"]) && !strstr(strtolower($curl_info["content_type"]), "html")) {
			return $url;
		}

		$this->profiler->startRecording('network');

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_NOBODY, 0);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);

		$body = curl_exec($ch);
		curl_close($ch);

		$this->profiler->stopRecording();

		if (trim($body) == "") {
			return $url;
		}

		// Check for redirect in meta elements
		$doc = new DOMDocument();
		@$doc->loadHTML($body);

		$xpath = new DomXPath($doc);

		$list = $xpath->query("//meta[@content]");
		foreach ($list as $node) {
			$attr = [];
			if ($node->attributes->length) {
				foreach ($node->attributes as $attribute) {
					$attr[$attribute->name] = $attribute->value;
				}
			}

			if (@$attr["http-equiv"] == 'refresh') {
				$path = $attr["content"];
				$pathinfo = explode(";", $path);
				foreach ($pathinfo as $value) {
					if (substr(strtolower($value), 0, 4) == "url=") {
						return $this->finalUrl(substr($value, 4), ++$depth);
					}
				}
			}
		}

		return $url;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch(string $url, int $timeout = 0, string $accept_content = '', string $cookiejar = '')
	{
		$ret = $this->fetchFull($url, $timeout, $accept_content, $cookiejar);

		return $ret->getBody();
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetchFull(string $url, int $timeout = 0, string $accept_content = '', string $cookiejar = '')
	{
		return $this->get(
			$url,
			[
				'timeout'        => $timeout,
				'accept_content' => $accept_content,
				'cookiejar'      => $cookiejar
			]
		);
	}
}
