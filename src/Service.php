<?php
/**
 * Web Socket Server: service
 *
 * @link http://infinity-systems.ru/
 */
namespace InfinitySystems\WebSocketServer;

use Exception;

/**
 * Class Daemon
 * @package InfinitySystems\WebSocketServer
 * @author Igor Sapegin aka Rendol <sapegin.in@gmail.com>
 */
class Service
{
	/**
	 * @var array Опции сервера
	 */
	public $opts = [];

	/**
	 * @var array Подключенные клиенты
	 */
	public $connections = [];

	/**
	 * @var array Регистрационные данные по клиентам
	 */
	public $registers = [];

	/**
	 * Init
	 *
	 * @param $config
	 * @return $this
	 */
	public function init($config = [])
	{
		$this->opts = $config + [
				'daemonMode' => false,
				'host' => 'localhost',
				'port' => 8001,
				'sleep' => 50000, // = 50 milliseconds
				'events' => [
					'connect' => function ($connection) {
						/** @var resource $connection */
					},
					'message' => function ($connection, $data, $status) {
						/** @var resource $connection */
						/** @var array $data */
						/** @var integer $status */
					},
					'iteration' => function ($connections) {
						/** @var resource[] $connections */
					},
					'eventBeforeRunCommand' => function ($data, $connectionId, $registerId) {
						/** @var array $data */
						/** @var integer $connectionId */
						/** @var integer $registerId */
					},
					'eventAfterRunCommand' => function ($result, $data, $connectionId, $registerId) {
						/** @var array $result */
						/** @var array $data */
						/** @var integer $connectionId */
						/** @var integer $registerId */
					},
					'disconnect' => function ($connection) {
						/** @var resource $connection */
					},
				]
			];
		return $this;
	}

	/**
	 * Run
	 */
	public function run()
	{
		if ($this->opts['daemonMode']) {
			// Создаем дочерний процесс
			// весь код после pcntl_fork() будет выполняться двумя процессами: родительским и дочерним
			$child_pid = pcntl_fork();
			if ($child_pid) {
				// Выходим из родительского, привязанного к консоли, процесса
				exit();
			}
			// Делаем основным процессом дочерний.
			posix_setsid();
		}

		$null = null;

		//Создаем сокет
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

		//многоразовый порт
		socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

		//привязываем сокет к порту
		socket_bind($socket, 0, $this->opts['port']);

		//ставим порт на прослушку
		socket_listen($socket);

		//create & add listning socket to the list
		$this->connections = array($socket);

		//делаем бесконечный цикл, чтобы наш скрипт не останавливался
		while (true) {


			try {
				//управление несколькими соединениями
				$connections = $this->connections;

				call_user_func($this->opts['events']['iteration'], $connections);

				//возвращает объект сокета в $connections array
				socket_select($connections, $null, $null, 0, 10);

				//проверяем, является ли соединение новым
				if (in_array($socket, $connections)) {
					//принимаем сокет
					$connection_new = socket_accept($socket);

					//добавляем сокет в массив клиентов
					$this->connections[] = $connection_new;

					//считываем данные из соединения
					$header = socket_read($connection_new, 1024);

					//делаем рукопожатие
					$this->_performHandshaking($header, $connection_new, $this->opts['host'], $this->opts['port']);

					call_user_func($this->opts['events']['connect'], $connection_new);

					//make room for new socket
					$found_socket = array_search($socket, $connections);
					unset($connections[$found_socket]);
				}

				//цикл для всех соединенных сокетов
				foreach ($connections as $connection) {
					try {
						//проверяем, есть ли новое сообщение
						while (@socket_recv($connection, $buf, 102400, 0) >= 1) {
							//декодируем его
							$receivedData = $this->_unmask($buf);
							$data = @json_decode($receivedData, true);

							call_user_func($this->opts['events']['message'], $connection, $data, ord($receivedData));

							break 2; //выходим из этого цикла
						}

						$buf = @socket_read($connection, 102400, PHP_NORMAL_READ);
						// проверяем, пустое ли сообщение
						if ($buf === false) {

							call_user_func($this->opts['events']['disconnect'], $connection);

							// удаляем соединение из списка клиентов
							$found_socket = array_search($connection, $this->connections);
							@socket_getpeername($connection, $ip);
							unset($this->connections[$found_socket]);

						}
					} catch (\Exception $e) {
						$this->sendMessage(
							[
								'Exception' => [
									'message' => $e->getMessage(),
									'code' => $e->getCode(),
									'trace' => $e->getTraceAsString(),
								]
							],
							[
								'connectionId' => intval($connection),
								'isGuest' => true
							]
						);
					}
				}

				call_user_func($this->opts['events']['iteration'], $connections);

				// 50 милисекунд
				usleep($this->opts['sleep']);

			} catch (Exception $e) {
				echo $e->getMessage();
			}
		}
		// закррываем соединение
		socket_close($socket);
	}

	/**
	 * Send message
	 *
	 * @param array $msg Сообщение
	 * @param array $opts Опции цели для отправки сообщения
	 * @return bool
	 * @throws Exception
	 */
	public function sendMessage($msg, $opts = [])
	{
		//
		$o = $opts + [
				'connectionId' => null,
				'registerId' => null,
				'isGuest' => false,
			];

		// file_put_contents(__DIR__.'/sendMessage.log', print_r([$msg, $opts, $o], true), FILE_APPEND);

		$msg = $this->_mask(json_encode($msg));

		if (strlen($msg) >= 65536) {
			throw new Exception('Packet size "' . strlen($msg) . '". Limit: 65536');
		}

		foreach ($this->connections as $connection) {
			$connectionId = intval($connection);

			// Проверка получателя по Id подключения
			if (null !== $o['connectionId']) {
				if ($o['connectionId'] != $connectionId) {
					continue;
				}
			}

			// Проверка получателя по Register Id подключения
			if (null !== $o['registerId']) {
				if (isset($this->registers[$connectionId])) {
					if ($o['registerId'] = $this->registers[$connectionId]['id']) {
						continue;
					}
				}
			}

			if ($o['isGuest'] == false && !isset($this->registers[$connectionId])) {
				continue;
			}

			//file_put_contents(__DIR__ . '/' . basename(__CLASS__) . '.log', print_r([$msg, strlen($msg)], true), FILE_APPEND);

			// Отправляем сообщение получателю
			@socket_write($connection, $msg, strlen($msg));
		}
		return true;
	}

	/**
	 * Decode message
	 *
	 * @param $text
	 * @return string
	 */
	private function _unmask($text)
	{
		$length = ord($text[1]) & 127;
		if ($length == 126) {
			$masks = substr($text, 4, 4);
			$data = substr($text, 8);
		} elseif ($length == 127) {
			$masks = substr($text, 10, 4);
			$data = substr($text, 14);
		} else {
			$masks = substr($text, 2, 4);
			$data = substr($text, 6);
		}
		$text = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$text .= $data[$i] ^ $masks[$i % 4];
		}
		return $text;
	}

	/**
	 * Encode message
	 *
	 * @param $text
	 * @return string
	 */
	private function _mask($text)
	{
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($text);

		$header = '';
		if ($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif ($length > 125 && $length < 65536)
			$header = pack('CCn', $b1, 126, $length);
		elseif ($length >= 65536)
			$header = pack('CCN', $b1, 127, $length);
		return $header . $text;
	}

	/**
	 * Handshaking
	 *
	 * @param $receved_header
	 * @param $connection_conn
	 * @param $host
	 * @param $port
	 */
	private function _performHandshaking($receved_header, $connection_conn, $host, $port)
	{
		$headers = array();
		$lines = preg_split("/\r\n/", $receved_header);
		foreach ($lines as $line) {
			$line = chop($line);
			if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
				$headers[$matches[1]] = $matches[2];
			}
		}

		$secKey = @$headers['Sec-WebSocket-Key'];
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		//hand shaking header
		$upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
			"Upgrade: websocket\r\n" .
			"Connection: Upgrade\r\n" .
			"WebSocket-Origin: $host\r\n" .
			//"WebSocket-Location: ws://$host:$port/demo/shout.php\r\n".
			"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		socket_write($connection_conn, $upgrade, strlen($upgrade));
		unset($port);
	}
} 