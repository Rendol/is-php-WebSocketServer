<?php
/**
 * Web Socket Server: example for user class
 *
 * @link http://infinity-systems.ru/
 */
namespace InfinitySystems\WebSocketServer;
/**
 * Class User
 * @package InfinitySystems\WebSocketServer
 * @author Igor Sapegin aka Rendol <sapegin.in@gmail.com>
 */
class User
{
	/**
	 * @var integer
	 */
	public $id;

	/**
	 * @var bool
	 */
	public $online = false;

	/**
	 * Find user by id
	 * @param $id
	 * @return User
	 */
	static function findOne($id)
	{
		$obj = new User();
		$obj->id = $id;
		return $obj;
	}

	/**
	 * User attributes
	 * @return array
	 */
	public function getAttributes()
	{
		return [
			'id' => $this->id,
			'online' => $this->online
		];
	}
}