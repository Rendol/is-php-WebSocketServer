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
	 * @param array $data
	 * @throws Exception
	 */
	public function eventRegister($connection, $data)
	{
		$connectionId = intval($connection);

		if (!isset($data['id'], $data['sessionId'])) {
			throw new Exception('Not found required attributes for register!');
		} else {

			@session_regenerate_id();
			@session_destroy();
			session_id($data['sessionId']);
			session_start();

			if (!isset($_SESSION[$this->sessionKey])) {
				throw new Exception('Not found "id" in session data!');
			} else {
				if ($data['id'] == $_SESSION[$this->sessionKey]) {
					$this->service->registers[$connectionId] = $data['id'];
					$this->runCommand([
						'method' => 'UserStatus',
						'params' => [true]
					], $connectionId, $data['id']);
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
			$connectionId = intval($connection);
			if (!isset($this->service->registers[$connectionId])) {
				// Регистрация
				if (isset($data['register'])) {
					$this->eventRegister($connection, $data['register']);
				}
			} // Сообщение зарегистрированному пользователю
			else {
				$this->runCommand($data, $connectionId);
			}
			if (isset($data['callback'])) {
				$this->service->sendMessage(
					[
						'callback' => $data['callback']
					],
					[
						'connectionId' => $connectionId
					]
				);
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
	 * @param $data
	 * @param $connectionId
	 * @param null $registerId
	 * @return bool
	 */
	public function eventBeforeRunCommand($data, $connectionId, $registerId)
	{
		return true;
	}

	/**
	 * @param $result
	 * @param $data
	 * @param $connectionId
	 * @param $registerId
	 */
	public function eventAfterRunCommand($result, $data, $connectionId, $registerId)
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
			$this->runCommand([
				'method' => 'UserStatus',
				'params' => [false]
			], $connectionId, $registerId);
		}
	}

	/**
	 * @param array $data
	 * @param integer $connectionId
	 * @param integer $registerId
	 */
	public function runCommand($data, $connectionId, $registerId = null)
	{
		if ($this->eventBeforeRunCommand($data, $connectionId, $registerId)) {

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
			$messageParams = $cmd->run($data);

			if ($messageParams) {
				call_user_func_array(
					[$this->service, 'sendMessage'],
					$messageParams
				);
			}

			$this->eventAfterRunCommand($messageParams, $data, $connectionId, $registerId);
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
