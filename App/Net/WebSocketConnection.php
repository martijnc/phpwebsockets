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

require_once __DIR__ . '/WebSocketFrame.php';
require_once __DIR__ . '/Frameable.php';
require_once __DIR__ . '/Socket.php';
require_once __DIR__ . '/../Web/HttpCookie.php';

/**
 * A WebSocketConnection is a client that has connected to a WebSocketServer.
 * The TCP connection is established and the TLS hanshake is done, the
 * WebSocket protocol related things are handled here. This class handles
 * the opening and closing handshake, can handle framing when sending messages
 * or can be used to send frames.
 * Objects which implement the ConnectionObserver interface can subscribe to events
 * or actions that happen on this socket by subscribing to them.
 */
class WebSocketConnection extends Socket
{
    
    /* Class contants used to identify the WebSocket connection state */
    const STATE_NEW                     = 0;
    const STATE_OPEN                    = 1;
    const STATE_CLOSING                 = 2;
    const STATE_CLOSED                  = 3;
    
    /**
     * A WebSockets opening handshake is HTTP compatible GET request that
     * contains the resource to load. (e.g. GET /resource/file.htm HTTP/1.1
     *
     * @var string The resource that was requested
     */
    protected $m_sRequestedResource     = '';
    
    /**
     * A WebSocketClient and WebSocketServer can negotiate a subprotocol to
     * use during the session. The client will send the protocol is wishes to
     * use in its opening handshake. These protocols will be stored in this 
     * array.
     *
     * @var array An array of allowed subprotocols.
     */
    protected $m_aRequestProtocols      = array();
    
    /**
     * A WebSocketClient and WebSocketServer can negotiate the subprotocol to
     * use during the session. The implementation of this protocol is fully
     * up to the application level but selection of the procotol is handled
     * here. The selection of subprotocols is explained by point 1.9 of the spec.
     *
     * @var array Array containing allowed subprotocols.
     */
    protected $m_aAllowedProtocols      = array();
    
    /**
     * A WebSocketClient and WebSocketServer can negotiate a subprotocol to
     * use during the session. When the server and client have agreed over
     * a protocol, it will be stored in this variable.
     *
     * @var string The protocol that was selected during the opening handshake
     */
    protected $m_sSelectedProtocol      = null;
    
    /**
     * The WebSocketServer uses an observer pattern to inform other objects
     * of changes and events. Objects that implement the ConnectionObserver interface
     * can subscrive to events and changes on a WebSocketConnection. 
     *
     * @var array Array containing all observers
     */
    protected $m_aObservers             = array();
    
    /**
     * This array will contain the headers that were sent by the client during
     * the opening handshake.
     *
     * @var array The headers that were send by the client
     */
    protected $m_aHeaders               = array();
    
    /**
     * The opening handshake can contain cookie information. This information will be
     * parsed into a associative array.
     *
     * @var array Associative array containing cookie information
     */
    protected $m_aCookies               = array();
    
    /**
     * The server can send cookies in the opening handshake that should be
     * handled by the client. The cookies that will be send during the handshake
     * are stored in this array.
     *
     * @var array An array containing HttpCookie objects
     */
    protected $m_aSetCookies            = array();
    
    /**
     * Messages can be broken into smaller pieces called frames.
     * These frames cannot be read simultaneous so we might need to buffer
     * some frames untill we read the final frame. Buffered frames are stored
     * in this array.
     *
     * @var array The buffered data that has already been read
     */
    protected $m_aFragmentBuffer        = array();
    
    /**
     * The non-blocking socket will store all data it reads in this array
     * from which the rest of the code will get its data.
     *
     * @var array The read buffer. Contains raw stream data.
     */
    protected $m_aReadBuffer            = array();
    
    /**
     * @var WebSocketFrame The frame that is currently being read.
     */
    protected $m_pCurrentFrame          = null;
    
    /**
     * @var int The message type of the message that is currently being read
     *          from this connection.
     */
    protected $m_nCurrentMessageType    = -1;
    
    /**
     * A WebSocketConnection has multiple states. this variable knows what the
     * current state of this connection is. (NEW, OPEN, CLOSING, CLOSED)
     *
     * @var int The current state of this connection
     */
    protected $m_nReadyState            = -1;
    
    /**
     * @var boolean True if the opening handshake from the client has been read
     */
    protected $m_bReadHandshake         = false;
    
    /**
     * @var boolean True if the response handshake has been send to the client
     */
    protected $m_bSendHandshake         = false;
    
    /**
     * The WebSockets closing handshake is a two part handshake. To handle this
     * properly we need to know whether we have recieved the closing frame.
     *
     * @var boolean True if the client has send its closing handshake
     */
    protected $m_bRecievedClose         = false;
    
    /**
     * The WebSockets closing handshake is a two part handshake. To handle this
     * properly we need to know whether we have already send a closing frame.
     *
     * @var boolean True if we have send a closing control frame
     */
    protected $m_bSendClose             = false;
    
    /**
     * @var int The numeric code as to why the connection was closed
     */
    protected $m_nCloseReason           = null;
    
    /**
     * @var string A string with a reason as to why the connection was closed
     */
    protected $m_sCloseReason           = null;
    
    /**
     * When the server tries to close a WebSocket connection but the client
     * does not reply with a closing frame in time we
     * are allowed to close the underlying TCP connection without completing the closing
     * handshake. This variable contains the time at which the closing handshake was
     * started.
     *
     * @var int The time at which the closing handshake was started.
     */
    protected $m_nCloseStartedAt        = null;

    /**
     * The WebSocketConnection constructor. Initializes the connection object and
     * retrieves host and port information from the remote host.
     *
     * @param resource $rSocket The newly accepted socket resource
     * @param array $aAllowedProtocols Array containing allowed subprotocols for the connection
     */
    public function __construct($rSocket, $aAllowedProtocols)
    {
        $this -> m_rSocket = $rSocket;
        
        /* Get the remote hosts host en port information and split them */
        $sRemoteHost = explode(':',
                                      stream_socket_get_name($this -> m_rSocket, true)
                              );
        
        /* Call parent contructor and pass the host info */
        parent :: __construct($sRemoteHost[0], $sRemoteHost[1]);
        
        /* Set the allowed subprotocols */
        $this -> m_aAllowedProtocols = $aAllowedProtocols;
        
        /* Update readystate and TCP status */
        $this -> m_nReadyState = self :: STATE_NEW;
        $this -> m_bConnected = true;
    }
    
    /**
     * WebSocket (client) connection are already open and can't be re-opened
     * so no need for this function but we must implement all abstract methods
     * from the socket class.
     */
    public function open() { return false; }
    
    /**
     * This accept method will accept the WebSocketConnection by going through
     * the opening handshake. At this point the TCP connection is already 
     * established and accepted.
     */
    public function accept()
    {

        /* Start by reading the handshake line by line and buffer it. */
        if (($sRead = $this -> get()) !== false) {
        
            /* fgets includes the newline at the end but we don't need that */
            $this -> m_aReadBuffer[] = substr($sRead, 0, strlen($sRead) - 2);
        }

        /* An empty line indicates the end of the headers (or opening handshake from the client) */
        if ($sRead == "\r\n") {

            /* So we can parse the handshake from the client */
            if ($this -> parseHandshake()) {
                
                /* And when it was succesfull raise the onHandshaeRecieved event */
                $this -> onHandshakeRecieved();
                
                /* When the handshake from the client was parsed succesfully (and was valid) we
                 * can do our part of the opening handshake. */
                if ($this -> doHandshake()) {
                    /* When we have send our part of the handshake, the WebSocketConnection is
                     * considered to be open, so update the ready state */
                    $this -> m_nReadyState = static :: STATE_OPEN;
                    
                    /* And yes, the handshake has been read */
                    $this -> m_bSendHandshake = true;
                    $this -> onOpen();
                } else {
                    /* If the handshake was invalid, the connection is closed */
                    $this -> m_nReadyState = static :: STATE_CLOSED;
                }
            }
            
        }
    }
    
    /** 
     * This method parses and validates the clients opening handshake.
     *
     * @return True if the handshake was valid and parsed succesfully
     */
    protected function parseHandshake()
    {
        /* Only start parsing if there is data to parse... */
        if (isset($this -> m_aReadBuffer[0])) {
        
            /* The first line contains the GET request */
            $sGetRequest = $this -> m_aReadBuffer[0];
            
            /* Break the GET line into pieces */
            $aParts = explode(' ', $sGetRequest);
            
            /* If the request is valid, there should be 3 parts */
            if (count($aParts) == 3) {
                
                /* The first MUST be GET. */
                if ($aParts[0] != 'GET') {
                
                    /* If it is not, send 405 Method Not Allowed status line. */
                    $this -> write('HTTP/1.1 405 Method Not Allowed' . "\r\n" . 'Allow: GET' . "\r\n\r\n");
                    
                    /* And close the connection */
                    $this -> close(1002);
                    return false;
                }
               
                /* The second part is the resource that was requested */
                $this -> m_sRequestedResource = $aParts[1];

                /* The HTTP version should be HTTP/1.1 or higher */
                if ($aParts[2] != 'HTTP/1.1') {
                    /* Close the connection if it's not */
                    $this -> write('HTTP/1.1 400 Bad Request' . "\r\n\r\n");
                    $this -> close(1002);
                    return false;
                }
            } else {
                /* Close the connection if the GET request line is invalid */
                $this -> write('HTTP/1.1 400 Bad Request' . "\r\n\r\n");
                $this -> close(1002);
                return false;
            }
            
            /* The GET line is parsed so we can clear the buffer */
            unset($this -> m_aReadBuffer[0]);
        }
        else
        {
            /* Seems there was no data send from the client */
            $this -> close(1002);
            return false;
        }

        /* Parse the other header lines that were in the opening handshake */
        foreach ($this -> m_aReadBuffer as $sHeader) {
            
            /* Split the name and value of the header fields */
            $aParts = explode(':', $sHeader, 2);
            
            /* There should be two parts... */
            if (count($aParts) == 2) {
                /* Store them in a array. Header names are case-insensitive. */
                $this -> m_aHeaders[trim(strtolower($aParts[0]))] = trim($aParts[1]);
            }

        }

        /* Reset the entire read buffer */
        $this -> m_aReadBuffer = array();

        /* Start validating the opening handshake. Point 5.2.1 of the procotol explains
         * how this should be done.
         *
         * The host field is a non-optional part of the handshake and thus MAY NOT be empty
         */
        if ($this -> getHeader('Host') == null) {
            $this -> write('HTTP/1.1 400 Bad Request' . "\r\n\r\n");
            $this -> close(1002);
            return false;
        }
        
        /* The Sec-WebSocket-Key header is a non-optional part of the opening handshake we'll
         * need to form our response to the opening handshake. */
        if ($this -> getHeader('Sec-WebSocket-Key') == null) {
            $this -> write('HTTP/1.1 400 Bad Request' . "\r\n\r\n");
            $this -> close(1002);
            return false;
        }

        /**
         * The Sec-WebSocket-Version is a non-optional part of the opening handshake and should
         * have the value 8. We don't support older versions of the protocol */
        if ($this -> getHeader('Sec-WebSocket-Version') != '13') {
            $this -> write('HTTP/1.1 400 Bad Request' . "\r\n\r\n");
            $this -> close(1002);
            return false;
        }
        
        /* If the Cookie header was present in the opening handshake from the client, send
         * it to the HttpCookie class that will parse it into a associative array. */
        if (($sCookieHeader = $this -> getHeader('Cookie')) != null) {
            $this -> m_aCookies = \App\Web\HttpCookie :: parseCookies($sCookieHeader);            
        }
        
        /* If the Sec-WebSocket-Protocol was present, we need to try and agree on the
         * subprotocol to use during this session. */
        if (($sProtocols = $this -> getHeader('Sec-WebSocket-Protocol')) != null) {
            
            /* Store the subprotocol list from the client in an array. The list as a
             * comma-separated list of values. So split and trim the values before
             * storing them. */
            $this -> m_aRequestProtocols = explode(',', $sProtocols);
            
            array_walk($this -> m_aRequestProtocols, function(&$sValue) { $sValue = trim($sValue); });
        }
        
        /* The opening handshake has been read. */
        $this -> m_bReadHandshake = true;
        
        /* Succesfully! */
        return true;

    }
    
    /**
     * This method will build and send our part of the opening handshake.
     * When we have send our part of the opening handshake, the connection
     * is considered to be open.
     *
     * @return boolean True if the handshake was send succesfully
     */
    protected function doHandshake()
    {
 
        /* At this point we already checked whether is header was set but we check
         * it again anyway */
        if ($this -> getHeaders('Sec-WebSocket-Key') != null) {
        
            /* The value of the Sec-WebSocket-Accept header is a bas64-encoded string of the SHA1
             * hash of the Sec-WebSocket-Key send by the client and a string.
             * Point 5.2.2 of the protocol explains how this key should be calculated */
            $sAcceptHeader = base64_encode(
                                           sha1(
                                                    $this -> getHeader('Sec-WebSocket-Key') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'
                                                    , true
                                                )
                                          );
        
            /* Start by sending the 101 Switching Protocols status line */
            $this -> write('HTTP/1.1 101 Switching Protocols' . "\r\n");
            
            /* The upgrade header */
            $this -> write('Upgrade: websocket' . "\r\n");
            $this -> write('Connection: Upgrade' . "\r\n");
            
            /* A bit of server info (optional)*/
            $this -> write('Server: PHP WebSockets' . "\r\n");
            
            /* The Sec-WebSocket-Accept key we calculated earlier. */
            $this -> write('Sec-WebSocket-Accept: ' . $sAcceptHeader . "\r\n");
            
            /* If there are cookies that need to be send to the client, send a Set-Cookie
             * header in the response as well. */
            if (count($this -> m_aSetCookies) > 0) {
                foreach ($this -> m_aSetCookies as $pCookie) {
                
                    /* Cookies are instances of the HttpCookie class which builds the
                     * valid set-string for us. */
                    $this -> write('Set-Cookie: ' . $pCookie -> toSetString() . "\r\n");
                }
            }
            
            /* This will try to select a subprotocol for this session */
            $this -> selectProtocol();
            
            /* And if we agreed on a subprotocol, add a Sec-WebSocket-Protocol header to the
             * response to inform the client. */
            if ($this -> m_sSelectedProtocol != null) {
                $this -> write('Sec-WebSocket-Protocol: ' . $this -> m_sSelectedProtocol . "\r\n");
            }
            
            /* OK, header is done. Inform the client by sending an empty line */
            $this -> write("\r\n");
        
            /* SUCCES */
            return true;
        
        } else {
        
            /* Something is wrong... */
            $this -> write('HTTP/1.1 400 Bad Request' . "\r\n\r\n");
            $this -> close(1002);
            return false;
        }

    }
    
    /**
     * This method tries to select the subprotocol to use during the current session.
     * The selection is up to the server but the client decides which protocols are
     * prefered. This method loops through the list of protocols send by the client and
     * tries to select one.
     */
    protected function selectProtocol()
    {        
        /* Loop through the list of protocols send by the client. Protocols at the beginning
         * of the array have prefered by the client. */
        foreach ($this -> m_aRequestProtocols as $sProcotol) {
            if (in_array($sProtocol, $this -> m_aAllowedProtocols)) {
            
                /* If we found a match, use that and stop searching */
                $this -> m_sSelectedProtocol = $sProtocol;
                return;
            }
        }
        
        /* No matches... */
        $this -> m_sSelectedProtocol = null;
    }
    
    /**
     * Because the sockets are in non-blocking mode to avoid stalling the whole
     * server when one client is slow, we read data from the socket and buffer it.
     * When there is data in the buffer we try to read and handle that. This method
     * should be called constantly so the data can be read and handled.
     * This method will not return any data. The appropiate events/methods will be called
     * in the observerving objects when something happened.
     */
    public function cycle()
    {

        /* We can't do anything with a closed socket...*/
        if (!$this -> m_bConnected) {
            return false;
        }
        
        /* When we send a closing handshake, we will wait 5 seconds for the client to do the
         * same. When we don't recieve a closing frame in time, we close the underlying TCP
         * connection. This is allowed by the protocol ;-)  */
        if ($this -> m_nCloseStartedAt != 0 && (time() - $this -> m_nCloseStartedAt) > 5)
        {
            $this -> close($this -> m_nCloseReason, $this -> m_sCloseReason);
        }

        /* Keep reading the handshake untill it is read and we've send a reply. */
        if (!($this -> m_bReadHandshake && $this -> m_bSendHandshake)) {
            
            /* this method will handle the opening handshake */
            $this -> accept();
            return;
        }

        /* The TCP connection was closed, no point in continueing */
        if (@feof($this -> m_rSocket)) {
            
            /* Close the connection with a 1006 statuts code (Not closed cleanly). */
            $this -> close(1006);
            return false;
        }
        
        /* Use the parent's read function to read data from the socket (if there is any) */
        $sData = parent :: read(2048);

        if ($sData === false) {
            return;
        }
        
        /* Unpack the byte string as bytes */
        $aData = unpack('C*', $sData);

        /* Append the newly read data to the end of the read buffer */
        $this -> m_aReadBuffer = array_merge($this -> m_aReadBuffer, $aData);
        
        /* If this property is -1, we aren't busy reading any message so we can
         * assume that this frame is the first frame of a new message */
        if ($this -> m_nCurrentMessageType == -1)
        {
            $bFirstFrame = true;
        }
        else
        {
            $bFirstFrame = false;
        }
        
        /* We need at least two bytes to start reading and parsing a frame */
        if ($this -> m_pCurrentFrame == null && $this -> getReadBufferSize() >= 2) {
            
            /* If there is enough data, create a new WebSocketFrame */
            $this -> m_pCurrentFrame = new WebSocketFrame();
        }
        
        /* if there is a WebSocketFrame */
        if ($this -> m_pCurrentFrame != null) {
        
            /* Start reading, if this method returns something other then 1004, the frame is
             * to large and cannot be read */
            if (($Result = $this -> m_pCurrentFrame -> read($this)) !== true) {
                $this -> disconnect(1004, 'Frame too large.');
            }

            /* When the frame is fully read, start processing it.
             * Frames can be read in multiple steps, just keep calling the read method untill
             * the result of the isComplete method is true.
             */
            if ($this -> m_pCurrentFrame -> isComplete()) {
            
                /* All frames send by a client MUST be masked or the connection MUST be closed
                 * protocol section 4.3 */
                if (!$this -> m_pCurrentFrame -> isMasked())
                {
                    $this -> disconnect(1002, 'Protocol error: Message should be masked.');
                    return false;
                }
            
                /* Message can be broken up into smaller parts which we call frames. These frame can be
                 * send seperatly. Only control frames may be injected between frames beloning to the
                 * same message. */
                if (!$bFirstFrame && $this -> m_pCurrentFrame -> getType() != WebSocketFrame :: TYPE_CONT
                    && !$this -> m_pCurrentFrame -> isControlFrame()) {
                    $this -> disconnect(1002, 'Protocol error: Mixing messages.');
                    return false;
                }
                
                /* If we recieve a control frame in between frames beloning to a seperate message, we
                 * have to raise the associated events before continueing reading the rest of the message */
                 if (!$bFirstFrame && $this -> m_pCurrentFrame -> isControlFrame())
                 {
                     $this -> onCompleteMessage($this -> m_pCurrentFrame -> getType(), array());
                     $this -> m_pCurrentFrame = null;
                     return true;
                 }
                
                /* Add the payload data in the newly read frame to the frame-read-buffer */
                $this -> m_aFragmentBuffer = array_merge($this -> m_aFragmentBuffer, $this -> m_pCurrentFrame -> getData());
                
                /* If this frame is the first of a new message, store the message type.
                 * The opcode in the following frames for this message will have a different
                 * opcode. */
                if ($bFirstFrame) {
                    $this -> m_nCurrentMessageType = $this -> m_pCurrentFrame -> getType();
                }

                /* If the newly read frame is the final frame of a full message we can process it further */
                if ($this -> m_pCurrentFrame -> isFinal()) {
                    /* This call will inform the observers */
                    $this -> onCompleteMessage($this -> m_nCurrentMessageType, $this -> m_aFragmentBuffer);
                    
                    /* Clear the frame-read buffer for the next message */
                    $this -> m_aFragmentBuffer = array();
                    
                    /* We want a new message! */
                    $this -> m_nCurrentMessageType = -1;
                }
                
                /* This frame is processed, prepare for the next one. */
                $this -> m_pCurrentFrame = null;
            }
        }
        
    }
    
    /**
     * This method will be called when a full message has been read from the
     * socket. The method will check the message type and call the correct
     * events.
     *
     * @param int $nType The type of the message
     * @param array $aData The actual data as a byte array
     */
    public function onCompleteMessage($nType, array $aData)
    {

        switch ($nType)
        {
            /* Both text and binary messages use the same event */
            case WebSocketFrame :: TYPE_TEXT:
            case WebSocketFrame :: TYPE_BINA:
                $this -> onMessage($nType, $aData);
                break;
                
            /* Ping control frames use onPing */
            case WebSocketFrame :: TYPE_PING:
                $this -> onPing();
                break;
            
            /* Pong control frames use onPong */
            case WebSocketFrame :: TYPE_PONG:
                $this -> onPong();
                break;
             
            /* Handling a close control frame is a bit more difficult */
            case WebSocketFrame :: TYPE_CLOSE:
            
                /* Start by setting this to true */
                $this -> m_bRecievedClose = true;

                $sReason = '';
                
                /* If we didn't start the closing handshake, start with the default
                 * 1005 status code (No status code was send) */
                if (!$this -> m_bSendClose)
                {
                    $nReason = 1005;
                } else {
                    $nReason = 0;
                }
                
                /* Status codes in a closing control frame are in the first two bytes
                 * of the payload data. If there are two or more bytes, there is a status code
                 * present. Try to parse that into a decimal number. */
                if (count($aData) >= 2)
                {
                    $nReason = ($aData[0] << 8) | $aData[1];
                    
                    /* If there is more then two bytes of data, there might be a UTF8 string
                     * in there as well. Read and parse that too. */
                    if (count($aData) > 2)
                    {
                        array_walk($aData, function(&$nData, $nIndex) { if ($nIdex > 1) $sReason .= chr($nData); });
                    }
                }

                /* And call disconnect(). This method will handle the closing handshake for us. */
                $this -> disconnect($nReason, $sReason);
                break;
            default:
                /* The status code that was recieved is not valid */
                $this -> disconnect(1003, 'Unknown data type');
        }
    }
    
    /** 
     * When a text or binary message were recieved over the socket, this method will be called.
     * If the message is a text message we convert the bytes to a UTF8 string. Binary data will be
     * left untouched. Finnaly the onMessage event will be called in all observers.
     *
     * @param int $nType The message type
     * @param array $aData The data as a byte array
     */
    public function onMessage($nType, $aData)
    {
        /* Convert the recieved bytes to UTF8 data if the message type is text */
        if ($nType == WebSocketFrame :: TYPE_TEXT)
        {
            array_walk($aData, function(&$nData) { $nData = chr($nData); });
            $aData = implode('', $aData);
        }
        
        /* Raise the onMessage event in the observers */
        foreach ($this -> m_aObservers as $pObserver)
        {
            $pObserver -> onMessage($this, $nType, $aData);
        }
    }
    
    /**
     * This method will be called when the WebSocketConnection was closed.
     * The status code or reason can be used to determine whether the connection
     * was closed cleanly or not.
     *
     * @param int $nReason The status code for the close
     * @param string $sReason The status code for the close (as string)
     */
    public function onClose($nReason, $sReason)
    {
        foreach ($this -> m_aObservers as $pObserver) {
            $pObserver -> onClose($this, $nReason, $sReason);
        }
    }
    
    /** 
     * This method will be called when the opening handshake from the client has been
     * read. This event will then we raised in all observing objects which at this point
     * can read and set cookies. Setting cookies isn't possible once the server has send
     * it's opening handshake in return.
     */
    public function onHandshakeRecieved()
    {
        foreach ($this -> m_aObservers as $pObserver) {
            $pObserver -> onHandshakeRecieved($this);
        }
    }
    
    /**
     * This method will be called when the server has send it's part of the opening
     * handshake in return. At this point the connection is considered to be open and
     * data can be send and read over the connection.
     */
    public function onOpen()
    {
        foreach ($this -> m_aObservers as $pObserver) {
            $pObserver -> onOpen($this);
        }
    }
    
    /** 
     * This method will be called when a ping control frame is recieved. The onPing
     * method will be called in all observing objects after a pong control frame is send
     * back to the client. This is a required by the protocol (point 4.5.2)
     */
    public function onPing()
    {
        
        /* Send back a pong control frame */
        $this -> pong();
    
        foreach ($this -> m_aObservers as $pObserver) {
            $pObserver -> onPing($this);
        }
    }
    
    /**
     * When a pong control frame was recieved by the connection, this method will
     * be called. This method will then call the onPong method in all observing
     * objects.
     */
    public function onPong()
    {
        foreach ($this -> m_aObservers as $pObserver) {
            $pObserver -> onPong($this);
        }
    }
    
    /**
     * This method sends a ping control frame to the client. The application
     * is responsable for checking whether the client sends a pong control frame
     * in return.
     */
    public function ping()
    {
        $this -> send(WebSocketFrame :: TYPE_PING);
    }
    
    /**
     * This method sends a pong control frame to the client.
     */
    public function pong()
    {
        $this -> send(WebSocketFrame :: TYPE_PONG);
    }
    
    /**
     * This method should be used when you want to send a message without having to bother
     * with framing yourself. Just give the data type and the data and this function will
     * handle the rest.
     * If you want to do framing yourself, use sendFrame() instead.
     *
     * @param int $nType The message type
     * @param array|string The data or text that would form the payload-data
     */
    public function send($nType, $aData = null)
    {
        
        /* Only send messages when the connection is in the OPEN or CLOSING state */
        if ($this -> m_nReadyState != static :: STATE_OPEN && $this -> m_nReadyState != static :: STATE_CLOSING) {
            return false;
        }

        /* If the message type is text, the data is a string that must be transformed into a
         * byte array. */
        if ($nType == WebSocketFrame :: TYPE_TEXT) {
    
            /* If there is data, split per character */
            if (strlen($aData) > 0) {
                $aData = str_split($aData);
            
                /* And transform each character into the corresponding byte */
                array_walk($aData, function(&$char) { $char = ord($char); });
            } else {
                 
                /* Or use an empty array */
                $aData = array();
            }
        }

        /* Determine how many frames will be needing to send this message */
        $nFramesNeeded = (int)(count($aData) / WebSocketFrame :: getMaxLength()) + 1;

        /* Send the date frame by frame */
        for ($i = 0; $i < $nFramesNeeded; $i++) {
            /* The first parameter is the message type. The actual message type must only be set
             * for the first frame, all following frames must have the continuation frame-type set
             * The second parameter is true if this is the final frame. False otherwise
             * The final parameter indicates whether the data should be masked. The protocol only states
             * that data send from the client MUST be masked. Data that is send by the server shouldn't be
             * masked. (Point 4.3)
             *
             */
            $pFrame = new WebSocketFrame(($i != 0)? 0: $nType, ($nFramesNeeded == $i + 1)? true: false, true);

            /* Get the piece of the message that should be send in the current frame. */
            if (count($aData) > 0) {
                $pFrame -> setData(array_slice($aData, WebSocketFrame :: getMaxLength() * $i,
                                   WebSocketFrame :: getMaxLength()));
            }

            /* And use the sendFrame method to actually send the frame to the client. */
            if (!$this -> sendFrame($pFrame)) {
                $this -> close(false);
                return false;
            }
            
        }

    }
    
    /**
     * This method takes objects that implement the Frameable interface
     * and will send the stream it gets from Frameable :: asByteStream()
     * to the client. This stream has to be a complete frame.
     *
     * @param Frameable $pFrame The frame that has to be send.
     * @return boolean True on succes, false otherwise
     */
    public function sendFrame(Frameable $pFrame)
    {
    
       /* Only send frame when the connection is in the opening or closing state. */
       if ($this -> m_nReadyState != static :: STATE_OPEN && $this -> m_nReadyState != static :: STATE_CLOSING) {
            return false;
        }
    
       /* The stream that will be send to the client */
       $sStream = '';
       
       /* Array containing the bytes that make up the frame in correct order */
       $aBytes = $pFrame -> asByteStream();

       /* Pack that array into a string (byte-string) */
       foreach ($aBytes as $nByte) {
            $sStream .= pack('C*', $nByte);
       }
       
       /* And send this to the client */
       return $this -> write($sStream);
    }
    
    /**
     * This method reads data from the read buffer and not directly from the socket.
     * Use this method to read data, reading data directly from the socket is highly
     * discouraged as the change of corrupting the stream is high.
     *
     * @param int $nBytes The number of bytes that you want to read.
     * @return array The data you requested
     */
    public function read($nBytes)
    {
        /* Array that we'll use to store the data */
        $aValues = array();
        
        /* See if there is enough data in the readbuffer, if there is not, change $nBytes
         * to the amount of bytes that are avaible. */
        if ($nBytes > count($this -> m_aReadBuffer))
        {
            $nBytes = count($this -> m_aReadBuffer);
        }
        
        /* Extract the correct amount of data from the readbuffer */
        $aValues = array_splice($this -> m_aReadBuffer, 0, $nBytes);
        
        /* And return that data */
        return $aValues;
    }
    
    /**
     * Use this method to write to the client.
     *
     * @param string $sMessage The data that has to be send to the client
     * @return boolean True on succes, false otherwise
     */
    public function write($sMessage)
    {
        if ($this -> m_bConnected) {
            return parent :: write($sMessage);
        }
        
        return false;
    }
    
    /**
     * This method will start the closing handshake or if a closing frame
     * from the client was already recieved, continue the closing handshake.
     * When the closing handshake is complete, the underlying TCP connection
     * will be closed and the onClose event will be raised.
     *
     * @param int $nReason The reason or status code
     * @param string $sReason the reason or status code
     */
    public function disconnect($nReason = null, $sReason = null)
    {
        
        /* If we have recieved a closing frame but not yet send one, do so now
         * and close the TCP connection.
         */
        if ($this -> m_bRecievedClose && !$this -> m_bSendClose) {
        
            /* Send closing control frame */
            $this -> send(WebSocketFrame :: TYPE_CLOSE);
            
            /* We might need to know this later on */
            $this -> m_bSendClose = true;
            
            /* Close TCP connection */
            $this -> close($nReason, $sReason);
            
            /* Update readystate */
            $this -> m_nReadyState = static :: STATE_CLOSED;
        } elseif (!$this -> m_bRecievedClose && !$this -> m_bSendClose) {
            /* At this point we should start the closing handshake */
            $aData = array();
            
            /* Before sending the closing frame, parse the numeric and textual reason */
            if ($nReason != null)
            {
                /* Split the reason up into two bytes */
                $aData[] = ($nReason & 0xFF00) >> 8;
                $aData[] = $nReason & 0xFF;

                /* The textual reason should be converted from UTF8 data to bytes */
                if ($sReason != null)
                {
                    $aReason = str_split($sReason);
                    array_walk($aReason, function(&$sChar) { $sChar = ord($sChar); });
                }
            }
            
            /* Add reason to the other data */
            $aData = array_merge($aData, $aReason);

            /* Send the frame to the client */
            $this -> send(WebSocketFrame :: TYPE_CLOSE, $aData);
            
            /* Store the closing reason in a property because the onClose event will
             * be raised later on and we'll need those then */
            $this -> m_nCloseReason = $nReason;
            $this -> m_sCloseReason = $sReason;
            
            /* Update send close property */
            $this -> m_bSendClose = true;
            
            /* Don't wait to long for the closing frame from the client. */
            $this -> m_nCloseStartedAt = time();
            
            /* Update the readystate */
            $this -> m_nReadyState = static :: STATE_CLOSING;
        } elseif ($this -> m_bRecievedClose && $this -> m_bSendClose) { 
            /* The closing handshake is complete, we can safely close the TCP connection */
            if ($nReason == null && $this -> m_nCloseReason != null)
            {
                $this -> close($this -> m_nCloseReason, $this -> m_sCloseReason);
            } else 
            {
                $this -> close($nReason, $sReason);
            }
        }
    }
    
    /**
     * This method will call the onClose method which will call this method in all
     * observing objects. After that, the TCP connection will be closed.
     * Use the disconnect() method if you want to close the WebSocket Connection cleanly
     *
     * @param int $nReason The status code or reason for the close
     * @param string $sReason The status code or reason for the close as text
     * @return boolean True on succes, false otherwise
     */
    public function close($nReason = null, $sReason = null)
    {
        
        /* Update the readystate */
        $this -> m_nReadyState = static :: STATE_CLOSED;
        
        /* Raise events */
        $this -> onClose($nReason, $sReason);
        
        /* Call the parent close function to close the TCP connection */
        return parent :: close();
    }
    
    /**
     * This method sets the socket in blocking or non-blocking mode depending
     * on the first parameter
     *
     * @param boolean $bBlocking True to put the socket in blocking mode, false for non-blocking
     */
    public function setBlocking($bBlocking) {
        stream_set_blocking($this -> m_rSocket, $bBlocking);
    }
    
    /**
     * This method should be used to set cookies. Cookies should be set using
     * this function before the opening handshake is complete.
     *
     * @param HttpCookie $pCookie Instance of HttpCookie that has the cookie information
     */
    public function setCookie(\App\Web\HttpCookie $pCookie)
    {
        $this -> m_aSetCookies[] = $pCookie;
    }
    
    /**
     * Objects who wish to observer this connection should implement the ConnectionObserver
     * interface and pass a reference to themselfs to this method. They will be informed
     * when something happens on the socket untill they unsubscribe.
     */
    public function subscribe(ConnectionObserver $pObserver)
    {
        if (array_search($pObserver, $this -> m_aObservers) === false) {
            $this -> m_aObservers[] = $pObserver;
        }
    }
    
    /**
     * Objects can use this function to unsubsribe themselfs from events that happen on
     * this socket.
     */
    public function unsubscribe(ConnectionObserver $pObserver)
    {
        if (($nIndex = array_search($pObserver, $this -> m_aObservers)) !== false) {
            unset($this -> m_aObservers[$nIndex]);
        }
    }
    
    /**
     * Use this method to get the current size of the readbuffer
     *
     * @return int The number of bytes in the readbuffer
     */
    public function getReadBufferSize()
    {
        return count($this -> m_aReadBuffer);
    }
    
    /**
     * This method can be used to get values of the headers that the client
     * send to the server in its opening handshake. The keys will be matched
     * case-insensitive.
     *
     * @retun string|null The value of the requested header or null if it
     *                    was not set.
     */
    public function getHeader($sKey)
    {
        /* Match case-insensitive */
        $sKey = strtolower($sKey);
    
        /* If the key exists, return the associated value. */
        if (array_key_exists($sKey, $this -> m_aHeaders)) {
            return $this -> m_aHeaders[$sKey];
        }
        
        /* Null will be returned if the key doesn't exist */
        return null;
    }
    
    /**
     * Use this method to get the value of a cookie that the client
     * has send in the opening handshake. Cookie keys are case-sensitive!
     *
     * @param string $sName The cookie's name
     * @return string The cookie's value or null if the cookie doesn't exist
     */
    public function getCookie($sName)
    {
        if (array_key_exists($sName, $this -> m_aCookies)) {
            return $this -> m_aCookies[$sName];
        }
        
        return null;
    }
    
    /**
     * Use this method to get the current readystate of this connection
     *
     * @return int The current readystate of the connection
     */
    public function getReadyState()
    {
        return $this -> m_nReadyState;
    }
    
    /**
     * This method returns all headers that the client has send in its opening
     * handshake as an associative array (key-value)
     *
     * @return array The associative headers array
     */
    public function getHeaders()
    {
        return $this -> m_aHeaders;
    }
}