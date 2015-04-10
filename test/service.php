<?php
/**
 * Service bootstrap file.
 */
use \InfinitySystems\WebSocketServer\Service;
use \InfinitySystems\WebSocketServer\Events;

include_once(__DIR__ . '/../src/User.php');
include_once(__DIR__ . '/../src/Commands.php');
include_once(__DIR__ . '/../src/Events.php');
include_once(__DIR__ . '/../src/Service.php');

/**
 * For test register user in session
 */
session_start();
session_id('xxxyyyzzz111222333444555');
$_SESSION['__id'] = 1;
session_commit();
session_regenerate_id();
@session_destroy();

/**
 * Run service
 */
$server = new Service([]);
$events = new Events($server, '\InfinitySystems\WebSocketServer\Commands');
$server
	->init([
		'events' => $events->getAll()
	])
	->run();
