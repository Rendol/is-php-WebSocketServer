<?php
/**
 * Web Socket Service: events
 *
 * @link http://infinity-systems.ru/
 */
namespace InfinitySystems\WebSocketServer;

/**
 * Class Commands
 * @package InfinitySystems\WebSocketServer
 * @author Igor Sapegin aka Rendol <sapegin.in@gmail.com>
 */
class Commands
{
	/**
	 * @var Service WebSocket service
	 */
	public $service;

	/**
	 * @var User Current user
	 */
	public $user;

	/**
	 * @var User[] Connected users
	 */
	public $users;

	/**
	 * @var integer Connection id
	 */
	public $connectionId;

	/**
	 * @param $config
	 */
	public function __construct($config = [])
	{
		foreach ($config as $param => $value) {
			$this->{$param} = $value;
		}
	}

	/**
	 * @param string $method
	 * @param array $params
	 * @return array
	 */
	public function run($method, $params)
	{
		return call_user_func_array(
			[$this, 'cmd' . $method],
			$params
		);
	}

	/**
	 * @param $data
	 * @return array
	 */
	public function forConnection($data)
	{
		return [
			$data,
			[
				'connectionId' => $this->connectionId
			]
		];
	}

	/**
	 * @param $data
	 * @return array
	 */
	public function forUser($data)
	{
		return [
			$data,
			[
				'registerId' => $this->service->registers[$this->connectionId]['id']
			]
		];
	}

	/**
	 * @param $data
	 * @return array
	 */
	public function forRegisters($data)
	{
		return [
			$data
		];
	}

	/**
	 * @param $data
	 * @return array
	 */
	public function forGuest($data)
	{
		return [
			$data,
			[
				'isGuest' => true
			]
		];
	}

	/**
	 * Список проектов пользователя
	 * @return array
	 */
	public function cmdUserList()
	{
		$list = [];
		foreach ($this->users as $rec) {
			$list[$rec->id] = $rec->getAttributes();
		}
		return $this->forConnection([
			'User' => [
				'list' => array_values($list)
			],
		]);
	}

	/**
	 * Статус пользователя
	 * @param $online
	 * @return array
	 */
	public function cmdUserStatus($online)
	{
		$this->user->online = $online;
		return $this->forRegisters([
			'User' => [
				'status' => [
					$this->user->getAttributes() + [
						'online' => $online,
					]
				]
			]
		]);
	}

}
