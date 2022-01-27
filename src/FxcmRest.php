<?php
namespace FxcmRest;

use FxcmRest\Constants\HttpMethod;

class FxcmRest extends \Evenement\EventEmitter {
	private $loop;
	private $httpClient;
	private $config;
	private $socketIO;
	
	function __construct(\React\EventLoop\LoopInterface $loop, Config $config) {
		$this->loop = $loop;
		$this->config = $config;
		$this->connector = new \React\Socket\Connector($loop);
		$this->httpClient = new \React\Http\Browser($this->connector, $loop);
		$this->socketIO = new SocketIO($this->loop, $this->config);

		$this->socketIO->on('connected', function() {
			$this->emit('connected');
		});

		$this->socketIO->on('data', function($data) {
			$json = json_decode($data);
			$this->emit($json[0], [$json[1]]);
		});

		$this->socketIO->on('error', function($e) {
			$this->emit('error', [$e]);
		});

		$this->socketIO->on('disconnected', function() {
			$this->emit('disconnected');
		});
	}
	
	function connect() {
		$this->socketIO->connect();
	}
	
	function disconnect() {
		$this->socketIO->disconnect();
	}
	
	function socketID() : string {
		return $this->socketIO->socketID();
	}
	
	function request(string $method, string $path, array $arguments, callable $callback) {
		$data = '';
		$url = $this->config->url() . $path;
		$arguments = http_build_query($arguments);
		$headers =[
			'User-Agent' => 'request',
			'Accept' => 'application/json',
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Authorization' => "Bearer {$this->socketIO->socketID()}{$this->config->token()}"
		];
		$end = "";
		if ($method === HttpMethod::POST) {
			$headers['Transfer-Encoding'] = 'chunked';
			$arglen = dechex(strlen($arguments));
			$end = "{$arglen}\r\n{$arguments}\r\n0\r\n\r\n";
		} else if ($method === HttpMethod::GET && $arguments) {
			$url .= "?" . $arguments;
		}

		$request = $this->httpClient
			->requestStreaming($method, $url, $headers)
			->then(function (\Psr\Http\Message\ResponseInterface $response) use ($data, $callback) {
			    $body = $response->getBody();
			   
			    assert($body instanceof \Psr\Http\Message\StreamInterface);
			    assert($body instanceof \React\Stream\ReadableStreamInterface);

			    $body->on('data', function ($chunk) use (&$data) {
			        $data .= $chunk;
			    });

			    $body->on('error', function (Exception $error) {
			        echo 'Error: ' . $error->getMessage() . PHP_EOL;
			    });

			    $body->on('close', function () use (&$data, $callback, $response) {
			        $callback($response->getStatusCode(), $data);
			    });
			}, function(\Exception $e) use ($callback) {
				$callback(0, $e);
			});
	}
}
?>