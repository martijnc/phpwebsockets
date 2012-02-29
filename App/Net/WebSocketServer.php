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

require_once __DIR__ . '/Socket.php';
require_once __DIR__ . '/ConnectionObserver.php';

/**
 * The WebSocketServer class extends the SocketServer class that handles
 * basic TCP things and adds support for the WebSockets Protocol. When a
 * WebSocketServer object is created and the open method is called, a
 * socket is opened that will listen for incoming connections on the given
 * port and IP. When creating a secure TLS server their has to be a valid
 * certificate present.
 */
class WebSocketServer extends Socket
{

    /**
     * The WebSocketServer uses an observer pattern to inform other objects
     * of changes and events. Objects that implement the ServerObserver interface
     * can subscrive to events and changes on a WebSocketServer. 
     *
     * @var array Array containing all observers
     */
    protected $m_aObservers             = array();
    
    /**
     * A WebSocketServer and WebSocketClient can negotiate a subprotocol that will
     * be used. The client will send a list of protocol is wishes to 'speak' and the
     * server will try to select one. The implementation of the actual protocol is handled
     * on application level but selecting a protocol is handled by the server.
     *
     * @var array All subprotocols the server wishes to speak
     */
    protected $m_aProtocols             = array();
    
    /**
     * The spec limits the number of connection from the same host that can be 
     * in the CONNECTING state to 1. This array is a reference to the array
     * containing all connections in the CONNECTING state so we can keep track of this
     *
     * @var array reference to the array containing all connections in the CONNECTION state
     */
    protected $m_aConnectionQueue       = array();

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
     * The open method opens a server socket that will start listening
     * for incoming connections on the ip and port passed through
     * the constructor. Calls the onServerOpen event/method
     *
     * @return boolean True on succes, false on error
     */    
    public function open()
    {
    
        $rContext = stream_context_create();
        
        /* If the bind IP is valid, bind the socket to it. */
        if (filter_var($this -> sBindIp, FILTER_VALIDATE_IP) !== false)
        {
            stream_context_set_option ($rContext, 'socket', 'bindto', $this -> sBindIp);
        }
        
        /* If this is a secure set some ssl paramaters on the socket. */
        if ($this -> m_bSecure)
        {
            /* Make sure this points to the servers certificate. Apache should have permissions to the file.
             * When your certificate requires a passphrase you should set that as well.
             */
            stream_context_set_option($rContext, 'ssl', 'local_cert','/path/to/your_cert.pem');
            stream_context_set_option($rContext, 'ssl', 'allow_self_signed',true); 
            stream_context_set_option($rContext, 'ssl', 'verify_peer', false);
        }
        
        /* Open the socket and return the result. */
        if ((filter_var($this -> m_sIp, FILTER_VALIDATE_IP) !== false) &&
            $this -> m_rSocket = @stream_socket_server('tcp://' . $this -> m_sIp . ':' . $this -> m_nPort,
                                                      $nError, $sError, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                                                      $rContext))
        {
            $this -> onServerOpen();
            return true;
        }
        else
        {
            return false;
        }
    }

    
    /**
     * Accept incoming client connections
     *
     * @return boolean True if a new connection has been accepted succesfully
     */
    public function accept() {
    
        $rConnection = null;
    
        /* Use parent :: accept to accept incoming connections */
        if ($rConnection = @stream_socket_accept($this -> m_rSocket, 0))
        {
            /* Get the remote hosts host en port information and split them */
            $aRemoteHost = explode(':', stream_socket_get_name($rConnection, true));

            /* Check if a connection from this IP is already in the CONNECTING state */
            if (array_key_exists($aRemoteHost[0], WebSocketConnection :: $aInConnectingState))
            {
                /* Queue it if so */
                $this -> m_aConnectionQueue[] = array('ip' => $aRemoteHost[0], 'conn' => $rConnection);
                $rConnection = null;
            }
            else
            {
                /* Or handle it otherwise */
                WebSocketConnection :: $aInConnectingState[$aRemoteHost[0]] = true;
            }
        }
        else
        {
            /* If no new connection are accepted, see if we can handshake with on old one */
            foreach ($this -> m_aConnectionQueue as $sKey => &$aConnection)
            {
                /* If the previous connection from this client is no longer CONNECTING */
                if (!array_key_exists($aConnection['ip'], WebSocketConnection :: $aInConnectingState))
                {
                    $rConnection = $aConnection['conn'];
                    WebSocketConnection :: $aInConnectingState[$aConnection['ip']] = true;
                    
                    /* Remove from queue */
                    unset($this -> m_aConnectionQueue[$sKey]);
                    
                    break;
                }
            }
            
            /* Unset foreach reference */
            unset($aConnection);
        }
         
         if ($rConnection && $rConnection !== null)
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

            /* Take the socket and wrap it in a nice new WebSocketConnection object */
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