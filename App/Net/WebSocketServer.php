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

require_once __DIR__ . '/SocketServer.php';
require_once __DIR__ . '/ConnectionObserver.php';

/**
 * The WebSocketServer class extends the SocketServer class that handles
 * basic TCP things and adds support for the WebSockets Protocol. When a
 * WebSocketServer object is created and the open method is called, a
 * socket is opened that will listen for incoming connections on the given
 * port and IP. When creating a secure TLS server their has to be a valid
 * certificate present.
 */
class WebSocketServer extends SocketServer
{

    /**
     * The WebSocketServer uses an observer pattern to inform other objects
     * of changes and events. Objects that implement the ServerObserver interface
     * can subscrive to events and changes on a WebSocketServer. 
     *
     * @var array Array containing all observers
     */
    protected $m_aObservers      = array();
    
    /**
     * A WebSocketServer and WebSocketClient can negotiate a subprotocol that will
     * be used. The client will send a list of protocol is wishes to 'speak' and the
     * server will try to select one. The implementation of the actual protocol is handled
     * on application level but selecting a protocol is handled by the server.
     *
     * @var array All subprotocols the server wishes to speak
     */
    protected $m_aProtocols      = array();

    /**
     * Constructor for the WebSocketServer class
     *
     * @param string $sHost The host or IP on which to listen
     * @param int $nPort The port number on which to listen
     * @param boolean $bSecure True if the server is using TLS (is secure)
     * @param string $sBindIp The IP to whit the server should be bind
     * @param array $aAllowedProtocols The allowed subprotocols
     */
    public function __construct($sHost, $nPort, $bSecure, $sBindIp = null, $aAllowedProtocols = array())
    {
        parent :: __construct($sHost, $nPort, $bSecure, $sBindIp);
        
        $this -> m_aProtocols = $aAllowedProtocols;
    }
    
    /**
     * This method will open the server socket and call the onServerOpen event/method
     * in all objects that subscribed to this server
     *
     * @return boolean True on succes (server is listening) or false on error
     */
    public function open()
    {
        if ($bResult = parent :: open())
        {
            $this -> onServerOpen();
        }
        
        return $bResult;
    }
    
    /**
     * 
     *
     * @return boolean True if a new connection has been accepted succesfully
     */
    public function accept() {
    
        /* Use parent :: accept to accept incoming connections */
        if ($rConnection = parent::accept())
        {
            /* If the server is using TLS, enable it on the new socket */
            if ($this -> m_bSecure)
            {
                $bTlsStatus = @stream_socket_enable_crypto($rConnection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            }
            
            /* If the TLS initializing was not succesfull, close the connection again. */
            if ($this -> m_bSecure && !$bTlsStatus)
            {
                fclose($rConnection);
                echo 'Terminated connection (TLS Failed)' . PHP_EOL;
                return false;
            }

            /* Use the socket and wrap it in a nice new WebSocketConnection object */
            $pNewConnection = new WebSocketConnection($rConnection, $this -> m_aProtocols);
            
            /* Put the socket in non-blocking mode so we can run multiple sockets in the
             * thread */
            $pNewConnection -> setBlocking(false);
            
            /* This will handle the handshake for us */
            $pNewConnection -> accept();
            
            /* Call/raise the onNewConnection method/event in all observers */
            $this -> onNewConnection($pNewConnection);
            
            /* YEEY! succes! */
            return true;
        }
        
        /* Nothing to report */
        return null;
    }
    
    /**
     * This method will call the onNewConnection in all observing objects. This happens
     * when a new connection is accepted succesfull. In case of a secure connection, the
     * TLS handshake will have been succesfull as well.
     *
     * @param WebSocketConnection $pNewConnection The instance of the new WebSocketConnection
     */
    public function onNewConnection($pNewConnection)
    {
        foreach ($this -> m_aObservers as $pObserver)
        {
            $pObserver -> onNewConnection($this, $pNewConnection);
        }
    }
    
    /**
     * This method will call the onServerOpen in all observing objects. This happens when a
     * server socket is opened and starts listening.
     */
    public function onServerOpen()
    {
        foreach ($this -> m_aObservers as $pObserver)
        {
            $pObserver -> onServerOpen($this);
        }
    }
    
    /**
     * This method will call the onServerClose in all observing objects. This happens when a
     * server socket is closed. New connections will no longer be accepted and the port will
     * be released.
     */
    public function onServerClose()
    {
        foreach ($this -> m_aObservers as $pObserver)
        {
            $pObserver -> onServerClose($this);
        }

    }
    
    /**
     * Objects who wish to observer this server should implement the ServerObserver
     * interface and pass a reference to themselfs to this method. They will be informed
     * when something happens on the socket untill they unsubscribe.
     */
    public function subscribe(ConnectionObserver $pObserver)
    {
        if (array_search($pObserver, $this -> m_aObservers) === false)
        {
            $this -> m_aObservers[] = $pObserver;
        }
    }
    
    /**
     * Objects can use this function to unsubsribe themselfs from events that happen on this socket.
     */
    public function unsubscribe(ConnectionObserver $pObserver)
    {
        if (($nIndex = array_search($pObserver, $this -> m_aObservers)) !== false)
        {
            unset($this -> m_aObservers[$nIndex]);
        }
    }

}