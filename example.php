<?php

/**
 * Create the following table:

CREATE TABLE `php_session` (
`id` char(32) NOT NULL DEFAULT '',
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data` mediumblob,
  `locked` tinyint(4) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

 */

require_once('MySqlLockSessionHandler.php');

// Database parameters can be set separately from the creation of the handler
MySqlLockSessionHandler::setDbParams('DATABASE', 'HOSTNAME', 'USER', 'PW', 'TABLE');

// Locking can be disabled if in a read-only process
// MySqlLockSessionHandler::disableLock();

// Create the new handler and assign it as the save handler
$oHandler = new MySqlLockSessionHandler();
session_set_save_handler($oHandler);

// Start a session and increment
session_start();
printf("Session ID: %s\n", session_id());
if (isset($_SESSION['x'])) {
    ++$_SESSION['x'];
    printf("Incremented the session value of x to %d\n", $_SESSION['x']);
} else {
    $_SESSION['x'] = 0;
    printf("Set the session value of x to 0\n");
}

$iSleep = 10;
echo "Sleeping for {$iSleep} - check for lock status\n";
sleep($iSleep);
echo "Done\n";
