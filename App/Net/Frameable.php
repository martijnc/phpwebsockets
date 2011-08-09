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

namespace App\net;

/**
 * The Frameable interface should be implemented by all classes who behave
 * like frames. This is useful when you want to do the framing yourself
 * but still would like the rest of the WebSocket server or client.
 */
interface Frameable
{
    /**
     * The asByteStream method should always return a FULL frame as a
     * byte array. When you pass an object to the WebSocketConnection :: sendFrame
     * method, this method will be used to get a byte stream (as an array) to
     * send over the socket. Always make sure you send complete frames, if
     * your frame is invalid or incomplete the connection will timeout or be
     * closed 'dirty' because frame send after the corrupt frame will not be
     * read correctly.
     *
     * @return array A full WebSockets frame.
     */
    public function asByteStream();
}