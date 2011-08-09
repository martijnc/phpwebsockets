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
 * This Socket class implements basic socket functionality. The class
 * handles closing, writing and reading. Some basic connection status
 * handling as well. All other Socket based classes extend this basic
 * class.
 *
 * @abstract
 */
abstract class Socket
{
    /**
     * Holds the socket resource.
     *
     * @var resource The socket resource
     */
    protected $m_rSocket;
    
    /**
     * Will keep track of the amount of incoming traffic send over
     * this socket.
     *
     * @var int Bytes received through this socket
     */
    protected $m_nBytesIn               = 0;
    
    /**
     * Will keep track of the amount of outgoing traffic send over
     * this socket.
     *
     * @var int Bytes send through this socket
     */
    protected $m_nBytesOut              = 0;
    
    /**
     * Gives the current status of the socket.
     *
     * @var boolean true if the connection is open, false if closed
     */
    protected $m_bConnected             = false;
    
    /**
     * The socket IP. For server sockets this is the local IP.
     * For client sockets this is the remote IP.
     *
     * @var string The ip used by the socket
     */
    protected $m_sIp;
    
    /**
     * The socket port. For server sockets this is the local port.
     * For client sockets this is the remote port.
     *
     * @var int The port used by the socket
     */
    protected $m_nPort;
    
    /**
     * @var boolean Indicates a secure connection
     */
    protected $m_bSecure;
    
    /**
     * @var string The IP to with to bind the socket
     */
    protected $sBindIp;
    
    /**
     * The constructor for the Socket class
     *
     * @param string $sIp The IP for this socket
     * @param int $nPort The port number for this socket
     * @param boolean $bSecure true if the connection is secure, false otherwise
     */
    public function __construct($sIp, $nPort, $bSecure = false, $sBindIp = null)
    {
        /* Set properties... */
        $this -> m_sIp         = $sIp;
        $this -> m_nPort       = $nPort;
        $this -> sBindIp       = $sBindIp;
        
        /* The openssl extension has to be loaded in order to use secure connections.
         * Extra detection for this extension is required, if it is not loaded the socket
         * will not be secure!
         */
        $this -> m_bSecure     = $bSecure && extension_loaded('openssl');
    }
    
    /**
     * Method for opening a socket. Opening a socket is different for
     * server and client sockets and must be implemented in subclasses.
     *
     * @abstract
     */
    abstract public function open();
    
    /**
     * Method that should be used to shutdown and close a stream socket.
     */
    public function close()
    {
        /* First shutdown the stream */
        stream_socket_shutdown($this -> m_rSocket, STREAM_SHUT_RDWR);

        /* and close it*/
        fclose($this -> m_rSocket);
        
        /* Update connection status */
        $this -> m_bConnected = false;
    }
    
    /**
     * Wrapper function for fgets on the socket. It detects when the
     * socket is closed and will handle a unexpected close of the
     * socket.
     *
     * @return string|boolean read data of false on error
     */
    public function get() {
       
       /* Try reading data from the stream. */
       if (($sData = @fgets($this -> m_rSocket)) !== false) {
            $this -> m_nBytesIn += strlen($sData);
        }
        
        /* Return the data that was read of false if reading has failed */
        return $sData;
    }
    
    /**
     * Wrapper function for fread on the socket, binary safe variant of fgets
     * It detects when the socket is closed and will handle a unexpected
     * close of the socket.
     *
     * @return string|boolean read data of false on error
     */
    public function read($nBytes) {
    
        /* Try reading data from the stream. */
        if (($sData = @fread($this -> m_rSocket, $nBytes)) !== false) {
            $this -> m_nBytesIn += strlen($sData);
        }

        /* Return the data that was read of false if reading has failed */
        return $sData;
    }
    
    /**
     * Wrapper for fwrite with connection loss detection.
     * 
     * @param string $sMessage The message that should be send over the socket
     * @return int number of bytes written
     */
    public function write($sMessage) {

        /* Write data to socket */
        if(($nBytes = @fwrite($this -> m_rSocket, $sMessage)) !== false) {
            $this -> m_nBytesOut += $nBytes;
        }
        
        /* return the number of read bytes */
        return $nBytes;
    }
    
    /**
     * Getter for socket is-secure.
     *
     * @return boolean True if connection is secure, false otherwise.
     */
    public function isSecure()
    {
        return $this -> m_bSecure;
    }
    
    /**
     * Getter for connection status
     *
     * @return boolean True if connected, false otherwise.
     */
    public function isConnected()
    {
        return $this -> m_bConnected;
    }
    
    /**
     * Getter the sockets IP or host. For server sockets this is the local
     * IP, for client sockets this is the remote IP.
     *
     * @return string The host or IP adres for this socket.
     */
    public function getHost()
    {
        return $this -> m_sIp;
    }
    
    /**
     * Getter the sockets port number. For server sockets this is the local
     * port number, for client sockets this is the remote port number.
     *
     * @return int The port number for this socket.
     */
    public function getPort()
    {
        return $this -> m_nPort;
    }
    
    /**
     * Getter the number of bytes recieved over this socket.
     *
     * @return int Number of incoming bytes.
     */
    public function getBytesIn()
    {
        return $this -> m_nBytesIn;
    }
    
    /**
     * Getter the number of bytes send over this socket.
     *
     * @return int Number of outgoing bytes.
     */
    public function getBytesOut()
    {
        return $this -> m_nBytesOut;
    }
}