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
 * The ConnectionObserver interface should be used to observe WebSocket
 * connections. The methods in this interface are used like events. Since
 * PHP is single-threaded, sockets operate in non-blocking mode. This means
 * we have to make our code asynchronous and use events. The methods in this
 * interface behave like events. Any class implementing this interface can
 * observe one or more WebSocket connections and subscribe to changes or events.
 * When these events or changes happen, the matching function will be called in
 * all observers.
 */
interface ConnectionObserver
{
    /**
     * The onMessage method is called when a WebSocket connection has read a full
     * message.
     *
     * @param WebSocketConnection $pConnection The connection object in which the event
     *                                         accured.
     * @param int $nType The event type.
     * @param string|array $sData In case of a text message this will be a string. In case of
     *                            binary data this will be a byte-array.
     */
    public function onMessage(WebSocketConnection $pConnection, $nType, $sData);
    
    /**
     * The onClose method is called in all classes who subscribed to events of the
     * WebSocket connection. Additionally, the reason is passed and whether the socket
     * was cleaned cleanly (completed the closing handshake).
     *
     * @param WebSocketConnection $pConnection The connection object in which the event
     *                                         accured.
     * @param int $nReason The reason for the connection close (numeric)
     * @param string $sReason The reason for the connection close (text)
     */
    public function onClose(WebSocketConnection $pConnection, $nReason, $sReason);
    
    /**
     * The onHandshakeRecieved method is called in all classes who subcribed to events
     * of the WebSocket connection. It is called directly after the handshake was read
     * and parsed. When a handshake appears to be invalid this method is never called
     * but the connection will be closed and the onClose method will be called instead.
     * Cookies can now be read and cookies can be send.
     * 
     * @param WebSocketConnection $pConnection The connection object in which the event
     *                                         accured.
     */
    public function onHandshakeRecieved(WebSocketConnection $pConnection);
    
    /**
     * The onOpen method is called in all classes who subscribed to the events of the WebSocket
     * connection when the connection is opened. A connection is open when the handshake is read,
     * parsed AND the server has send its handshake in return.
     * When all this has happened, the connection is in OPEN state.
     * You can start sending data now. Setting new cookies is useless since the protocol only
     * supports -like http- the setting of cookies before any data is send.
     * 
     * @param WebSocketConnection $pConnection The connection object in which the event
     *                                         accured.
     */
    public function onOpen(WebSocketConnection $pConnection);
    
    /**
     * The onPing method is called in all classes who subscribed to the events of the WebSocket
     * connection when a ping control frame is received. There is no need to manually send a
     * pong back. Since it is requirement in the standard the WebSocketserver or client will do
     * this implicitly.
     * 
     * @param WebSocketConnection $pConnection The connection object in which the event
     *                                         accured.
     */
    public function onPing(WebSocketConnection $pConnection);
    
    /**
     * The onPong method is called in all classes who subscribed to the events of the WebSocket
     * connection when a pong control frame is received. The WebSockets procotol does not has any
     * requirments on when to send a ping. Thus sending pings and detecting pongs should be handled
     * on application level. However, replies to a ping are mandatory in the protocol and thus handled
     * on protocol level.
     *
     * @param WebSocketConnection $pConnection The connection object in which the event
     *                                         accured.
     */
    public function onPong(WebSocketConnection $pConnection);
}