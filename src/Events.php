<?php
/**
 * Web Socket Service: events
 *
 * @link http://infinity-systems.ru/
 */
namespace InfinitySystems\WebSocketServer;

use Exception;

/**
 * Class Events
 * @package InfinitySystems\WebSocketServer
 * @author Igor Sapegin aka Rendol <sapegin.in@gmail.com>
 */
class Events
{
	/**
	 * @var string
	 */
	public $sessionKey = '__id';

	/**
	 * @var Service
	 */
	public $service;

	/**
	 * @var array
	 */
	public $store;

	/**
	 * @var string
	 */
	public $cmdClass;

	/**
	 * @var string
	 */
	public $userClass;

	/**
	 * @param $service Service
	 * @param $cmdClass string
	 * @param $userClass string
	 */
	public function __construct($service, $cmdClass, $userClass = 'InfinitySystems\WebSocketServer\User')
	{
		$this->service = $service;
		$this->cmdClass = $cmdClass;
		$this->userClass = $userClass;
	}

	public function getAll()
	{
		return [
			'connect' => [$this, 'eventConnect'],
			'register' => [$this, 'eventRegister'],
			'message' => [$this, 'eventMessage'],
			'iteration' => [$this, 'eventIteration'],
			'beforeRunCommand' => [$this, 'eventBeforeRunCommand'],
			'afterRunCommand' => [$this, 'eventAfterRunCommand'],
			'disconnect' => [$this, 'eventDisconnect'],
		];
	}

	/**
	 * @param resource $connection
	 */
	public function eventConnect($connection)
	{
		// empty
	}

	/**
	 * @param resource $connection
	 * @param array $params
	 * @throws Exception
	 */
	public function eventRegister($connection, $params)
	{
		$connectionId = intval($connection);

		if (!isset($params['id'], $params['sessionId'])) {
			throw new Exception('Not found required attributes for register!');
		} else {

			@session_regenerate_id();
			@session_destroy();
			session_id($params['sessionId']);
			session_start();

			if (!isset($_SESSION[$this->sessionKey])) {
				throw new Exception('Not found "id" in session data!');
			} else {
				if ($params['id'] == $_SESSION[$this->sessionKey]) {
					$this->service->registers[$connectionId] = $params['id'];

					$this->service->sendMessage(
						[
							0 => Service::TYPE_ID_SUBSCRIBE,
						],
						[
							//'connectionId' => $connectionId
						]
					);

					$this->runCommand('UserStatus', [true], $connectionId, $params['id']);
				}
			}

			session_regenerate_id();
			session_destroy();

		}
	}

	/**
	 * @param resource $connection
	 * @param array $data
	 * @param integer $status
	 */
	public function eventMessage($connection, $data, $status)
	{
		if (3 == $status) {
			$this->eventDisconnect($connection);
		} else {
			if (isset($data[1])) {
				$method = $data[1];
				$params = isset($data[2]) ? $data[2] : [];

				$connectionId = intval($connection);
				if (!isset($this->service->registers[$connectionId])) {
					// Регистрация
					if ($method == 'register') {
						$this->eventRegister($connection, $params);
					}
				} // Сообщение зарегистрированному пользователю
				else {
					$this->runCommand($method, $params, $connectionId);
				}

				if (isset($data[3])) {
					$this->service->sendMessage(
						[
							Service::TYPE_ID_CALLRESULT,
							[
								'callback' => $data[3]
							]
						],
						[
							'connectionId' => $connectionId
						]
					);
				}
			}
		}
	}

	/**
	 * @param resource[] $connections
	 */
	public function eventIteration($connections)
	{
		//
	}

	/**
	 * @param $method
	 * @param $params
	 * @param $connectionId
	 * @param null $registerId
	 * @return bool
	 */
	public function eventBeforeRunCommand($method, $params, $connectionId, $registerId)
	{
		return true;
	}

	/**
	 * @param $result
	 * @param $method
	 * @param $params
	 * @param $connectionId
	 * @param $registerId
	 */
	public function eventAfterRunCommand($result, $method, $params, $connectionId, $registerId)
	{
		//
	}

	/**
	 * @param resource $connection
	 */
	public function eventDisconnect($connection)
	{
		$connectionId = intval($connection);
		$registerId = $this->service->registers[$connectionId];
		unset($this->service->registers[$connectionId]);
		if (!in_array($registerId, $this->service->registers)) {
			unset($this->store['users'][$registerId]);
			$this->service->sendMessage(
				[
					0 => Service::TYPE_ID_UNSUBSCRIBE,
				],
				[
					'connectionId' => $connectionId
				]
			);
			$this->runCommand('UserStatus', [false], $connectionId, $registerId);
		}
	}

	/**
	 * @param array $data
	 * @param integer $connectionId
	 * @param integer $registerId
	 */
	public function runCommand($method, $params, $connectionId, $registerId = null)
	{
		if ($this->eventBeforeRunCommand($method, $params, $connectionId, $registerId)) {

			if (empty($registerId)) {
				$registerId = $this->service->registers[$connectionId];
			}
			$user = $this->_getUser($registerId);

			/** @var Commands $cmd */
			$cmd = new $this->cmdClass([
				'service' => $this->service,
				'user' => $user,
				'users' => &$this->store['users'],
				'connectionId' => $connectionId
			]);
			$results = $cmd->run($method, $params);
			if ($results) {
				call_user_func(
					[$this->service, 'sendMessage'],
					[
						Service::TYPE_ID_EVENT,
						$results[0],
						$params
					],
					isset($results[1]) ? $results[1] : []
				);
			}

			$this->eventAfterRunCommand($results, $method, $params, $connectionId, $registerId);
		}
	}

	/**
	 * @param $registerId
	 * @return User
	 * @throws Exception
	 */
	private function _getUser($registerId)
	{
		if (empty($this->store['users'])) {
			$this->store['users'] = [];
		}

		$user = null;
		if ($registerId) {
			$users = &$this->store['users'];
			if (empty($users[$registerId])) {
				$users[$registerId] = call_user_func([$this->userClass, 'findOne'], $registerId);
			}
			$user = $users[$registerId];
		}

		if (null === $user) {
			throw new \Exception('User not found');
		}
		return $user;
	}
}
