<?php
/**
 * Copyright (c) 2011 Martijn Croonen
 *
 * This file is a demo chatserver based on a PHP WebSockets server.
 *
 * PHP WebSockets is licensed under the terms of the MIT license which can be
 * found in the LICENSE file.
 *
 * @copyright Copyright (c) 2011 Martijn Croonen <martijn@martijnc.be>
 * @author Martijn Croonen <martijn@martijnc.be>
 */

require_once __DIR__ . '/ChatServer.php';

 
/*
 * The ChatServer class provides a small and simple chatserver that is build
 * on top of a PHP WebSockets server and thus uses the WebSockets protocol.
 */
$pChatServer = new App\Net\Subprotocols\ChatServer('0.0.0.0', 8081);
$pChatServer -> start();