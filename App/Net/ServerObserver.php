<?php
/**
 * Copyright (c) 2011 Martijn Croonen
 *
 * This file is part of PHP WebSockets.
 * 
 * PHP WebSockets is an implementation of the WebSockets protocol.
 * The WebSockets protocol is being standardized by the IETF and allows two-way
 * communication between a client (e.g. Your web browser) and a remote host
 * (e.g. a WebSockets Server).
 *
 * PHP WebSockets is licensed under the terms of the MIT license which can be
 * found in the LICENSE file.
 *
 * @copyright Copyright (c) 2011 Martijn Croonen <martijn@martijnc.be>
 * @author Martijn Croonen <martijn@martijnc.be>
 */

namespace App\Net;

/**
 * The ServerObserver interface should be used to observe WebSocket
 * servers. The methods in this interface are used like events. Since
 * PHP is single-threaded, sockets operate in non-blocking mode. This means
 * we have to make our code asynchronous and use events. The methods in this
 * interface behave like events. Any class implementing this interface can
 * observe one or more WebSocket servers and subscribe to changes or events.
 * When these events or changes happen, the matching function will be called in
 * all observers.
 */
interface ServerObserver
{
    /**
     * This method will be called in all observers who subscribed to events for
     * the WebSockets server when it recieves a new connection. The new connection
     * is a TCP connection, the handshake has not yet been read or send.
     * You can close() the connection here rather then disconnect()-ing it since
     * the WebSocketConnection is not yet in OPEN state. Only the TCP connection is
     * open and in case of a secure server the TLS handshake is done.
     * 
     * @param WebSocketServer $pServer The instance of the WebSocketServer in
     *                                 which the event accured.
     * @param WebSocketConnection $pConnection Instance of the new connection
     */
    public function onNewConnection(WebSocketServer $pServer, WebSocketConnection $pConnection);
    
    /**
     * This method is called in all observers who subscribed to events for
     * the WebSockets server when the server is opened and starts listening.
     * 
     * @param WebSocketServer $pServer The instance of the WebSocketServer in
     *                                 which the event accured.
     */
    public function onServerOpen(WebSocketServer $pServer);
    
    /**
     * the onServerClose method is called in all observers who subscribed to events for
     * the WebSockets server when the server is closed and stops listening.
     * 
     * @param WebSocketServer $pServer The instance of the WebSocketServer in
     *                                 which the event accured.
     */
    public function onServerClose(WebSocketServer $pServer);
}