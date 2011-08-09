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

/**
 * The SocketServer class adds server functionality to the Sockets class
 * by extending it. It implements the abstract SocketServer :: open method
 * to create a server socket.
 * The server will listen on a port for new incoming connections and accepts
 * them.
 */
class SocketServer extends Socket
{
    /**
     * The open method opens a server socket that will start listening
     * for incoming connections on the ip and port passed through
     * the constructor.
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
            return true;
        }
        else
        {
            return false;
        }
    }
    
    /**
     * This method will accept incoming connections on the server socket.
     *
     * @return resource|boolean The accepted socket or false on failure
     */
    public function accept()
    {
        /* Accept new connections if there is one, don't block the calling tree */
        if ($rConnection = @stream_socket_accept($this -> m_rSocket, 0))
        {
            return $rConnection;
        }
        else
        {
            return false;
        }
    }
}