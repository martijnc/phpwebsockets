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

require_once __DIR__ . '/Frameable.php';

/**
 * The WebSocketFrame implements the framing required by the WebSockets
 * protocol. Messages can be divided into smaller frames so data can
 * be send before the whole message is ready. The class implements the
 * Frameable interface. If you want to take care of framing yourself you
 * can use this function in combination with the WebSocketConnection :: sendFrame
 * method.
 * Frames are parsed once and then cached untill the data is changed so
 * there is no performance less when sending the same frame multiple times.
 */
class WebSocketFrame implements Frameable
{
    
    /*
     * There class constants are the opcodes that need to be set in the frames.
     */
    const TYPE_CONT      = 0x0;
    const TYPE_TEXT      = 0x1;
    const TYPE_BINA      = 0x2;
    const TYPE_CLOSE     = 0x8;
    const TYPE_PING      = 0x9;
    const TYPE_PONG      = 0xA;
    
    /**
     * Large messages can be send in smaller frames. To make it possible to start sending
     * frames before the length of the complete message it is know, we need a different 
     * method to tell the remote host the message is complete. Each frame has a reserved
     * bit to indicate whether this frame is the last part of a message.
     *
     * @var boolean $m_bFinal Indicates whether this is the final frame
     */
    protected $m_bFinal               = true;
    
    /**
     * Every frame has an opcode that contains information as to what the frame contains. There
     * are 2 types of messages, binary and text. When messages are fragmented, only the first
     * frame has the type information, all next frames have to 'continuation-frame' opcode.
     * Beside those three types, there are three types of control frames; close, ping and pong.
     * Control frames may not be fragmented.
     *
     * @var int $m_nType The current frame's type
     */
    protected $m_nType                = 0x0;
    
    /**
     * Frames send from a client (e.g.: a browser) MUST be masked. When a server recieves an unmasked
     * frame from a client, the connection MUST be dropped. Masking is done using simple
     * XOR encryption and is there as a security measure to protect from corrupt intermediates.
     * If this bit is set, the frame will contain a 32 bit (4 byte) masking key.
     *
     * @var boolean True if the frame is masked, false otherwise.
     */
    protected $m_bMasked              = true;
    
    /**
     * Each frame has a length property that tells how much data it contains. While we can start
     * sending  messages before the length is known, it is not possible to send a frame without
     * knowning it's length.
     * 
     * @var int $m_nPayLoadLength The length of the data in the frame.
     */
    protected $m_nPayLoadLength       = 0;
    
    /**
     * Each frame has 3 bits that are reserved for extensions and can be allocated on a per
     * frame basis. The implementation and meaning of these bits is up to the extenions.
     *
     * @var int $m_nRsv These bits are reserved for extensions
     */
    protected $m_nRsv                 = 0x0;
    
    /**
     * The masking key is used for frame masking. The masking key is 32bit in length.
     * Masking is done through some simple XOR encryption of the payload data. Each bit
     * of the masking key has a index between 0 and 3 in the array.
     *
     * @var array $m_aMaskingKey An 4 byte array containing the masking key
     */
    protected $m_aMaskingKey          = array();
    
    /**
     * An array containing the payload data. The data should be a byte array. When the
     * frame is binary, the interpretation is up to the application. When the frame is
     * a text frame, the bytes will be converted to UTF8.
     * 
     * @var array $m_aData The payload data as bytes
     */
    protected $m_aData                = array();
    
    /**
     * Frames can be read from the socket in small parts. It could happen that a frame
     * is't fully read during one cycle. This boolean indicates whether the frame is
     * fully read and can be used. 
     *
     * @var boolean $m_bIsComplete Indicates whether the frame is complete
     */
    protected $m_bIsComplete          = false;
    
    /**
     * When a frame is fully parsed, it will be stored in this array. When this frame
     * is send multiple times, there is no need to re-parse it everytime. When the frame
     * is changed (new data,...) the frame will have to be reparsed ofcourse.
     *
     * @var array $m_aParsed A byte array containing the parsed frame
     */
    protected $m_aParsed              = false;
    
    /**
     * The maximum length for frames defined by the protocol is the maximum value
     * of a 64 bit unsigned integer (so 2^63). The protocol doesn't define a minimum length.
     * For convience we use the PHP_INT_MAX value by default. This value is only used while
     * sending frames
     *
     * @var int $m_nMaxLengthOut The maximum length for outgoing frames
     */
    protected static $m_nMaxLengthOut    = PHP_INT_MAX;
    
    /**
     * The maximum length for frames defined by the protocol is the maximum value
     * of a 64 bit unsigned integer (so 2^63). The protocol doesn't define a minimum length.
     * When we recieve a frame larger than this value the connection will be closed.
     *
     * @var int $m_nMaxLengthOut The maximum length for incoming frames
     */
    protected static $m_nMaxLengthIn    = PHP_INT_MAX;
    
    /**
     * It possible to read frames in smaller parts as they are received by
     * the socket. To keep track of which parts have already been read we
     * need some booleans.
     *
     * @var boolean If true the first two bytes have been read
     */
    protected $m_bFirstBytesRead      = false;
    
    /**
     * It possible to read frames in smaller parts as they are received by
     * the socket. To keep track of which parts have already been read we
     * need some booleans.
     *
     * @var boolean If true the payload length has been read
     */
    protected $m_bLengthRead          = false;
    
    /**
     * It possible to read frames in smaller parts as they are received by
     * the socket. To keep track of which parts have already been read we
     * need some booleans.
     *
     * @var boolean If true the maskingkey has been read
     */
    protected $m_bMaskingKeyRead       = false;
    
    /**
     * The constructor for the WebSocketFrame class
     *
     * @param int $nType The opcode for this frame
     * @param boolean $bFinal Is this the final frame?
     * @param boolean $bMasked Indicates whether the frame is masked
     * @param int $nRsv The three bits that are reserved for extensions
     */
    public function __construct($nType = 0x0, $bFinal = true, $bMasked = true, $nRsv = 0x0)
    {
        $this -> m_bFinal             = $bFinal;
        $this -> m_nRsv               = $nRsv;
        $this -> m_nType              = $nType;
        $this -> m_bMasked            = $bMasked;
        
        /* Control frames may not be fragmented */
        if ($this -> isControlFrame())
        {
            $this -> m_bFinal = true;
        }     
    }
    
    /**
     * This function will read a full WebSocketFrame from the connection
     * that was passed as the first parameter. Keep calling this function
     * untill the frame has been read completely because a frame might
     * need to be read in parts from the socket.
     *
     * @param WebSocketConnection $pConnection The WebSocketConnection from which to read the frame
     */
    public function read(WebSocketConnection $pConnection)
    {

        /* If we haven't read the first 2 bytes and the read-buffer is smaller
         * then 2 bytes, wait for the next round and hope the bytes will be
         * available then. */
        if (!$this -> m_bFirstBytesRead && $pConnection -> getReadBufferSize() < 2)
        {
            return true;
        }
        
        /* At this point we are sure there are at least 2 bytes in the read-buffer,
         * so we can start by reading the first 2 if we haven't already done that. 
         * I'll refer to these two bytes as header*/
        if (!$this -> m_bFirstBytesRead)
        {
            /* Read the two bytes */
            $nData = $pConnection -> read(2);
            $this -> m_bFirstBytesRead = true;

            /* Extract the values from the bytes using bit-wise operations.
             * Point 4.2 of the protocol goes more into details as to how
             * WebSocket Frames are formed */
            $this -> m_bFinal             = ((0x80 & $nData[0]) == 0x80) ? true : false;
            $this -> m_nRsv               = (0x70 & $nData[0]) >> 4;
            $this -> m_nType              = 0xF & $nData[0];
            $this -> m_bMasked            = ((0x80 & $nData[1]) == 0x80) ? true : false;
            $this -> m_nPayLoadLength     = 0x7F & $nData[1];
        }

        /* If the payloadlength in the header is 126, the actual payload length
         * is in the next two bytes. So read those. If the value in the header
         * is 127, the actual payloadlength is in the next 8 bytes. */
        if (!$this -> m_bLengthRead && $this -> m_nPayLoadLength == 126)
        {
            if ($pConnection -> getReadBufferSize() >= 2)
            {
                $this -> m_nPayLoadLength = $this -> repack($pConnection -> read(2));
                $this -> m_bLengthRead = true;
            }
        }
        elseif (!$this -> m_bLengthRead && $this -> m_nPayLoadLength == 127)
        {
        
            if ($pConnection -> getReadBufferSize() >= 8)
            {
                $this -> m_nPayLoadLength = $this -> repack($pConnection -> read(8));
                $this -> m_bLengthRead = true;
            }
        }
        else
        {
            /* Or the payloadlength in the header was the actual payloadlength */
            $this -> m_bLengthRead = true;
        }
        
        /* If the payloadlength is larger then WebSocketFrame :: m_nMaxLengthIn we can't process it properly */
        if ($this -> m_nPayLoadLength > static :: $m_nMaxLengthIn)
        {
            return false;
        }

        /* The header contains a masking-bit. When this bit is set, the next 4 bytes are a 
         * masking key that is used to mask the payloaddata using XOR encryption. */
        if (!$this -> m_bMaskingKeyRead && $this -> m_bMasked && count($this -> m_aMaskingKey) == 0)
        {
           
            if ($pConnection -> getReadBufferSize() >= 4)
            {
                $this -> m_aMaskingKey = $pConnection -> read(4);
                $this -> m_bMaskingKeyRead = true;
            }
        }

        /* If there is enough data in the read-buffer, read the data. */
        if ($pConnection -> getReadBufferSize() >= $this -> m_nPayLoadLength)
        {

            $this -> m_aData = $pConnection -> read($this -> m_nPayLoadLength);

            /* If the masking bit is set, unmask the data using the masking-key we
             * read from the stream earlier. */
            if ($this -> m_bMasked)
            {
                $this -> unMask();
            }
            
            /* At this point the frame has been read completely */
            $this -> m_bIsComplete = true;
            
        }
        else
        {
            /* The frame is not complete yet */
            $this -> m_bIsComplete = false;
        }
        
        /* Nothing went wrong! YEEY! */
        return true;
    }
    
    /**
     * Part of the Frameable interface. This method will when called return a byte array
     * containing a valid frame. It will mask the frame if the masking bit is set.
     */
    public function asByteStream()
    {
        
        /* If the frame cache is larger or equal to two bytes, the frame has already been
         * been parsed and cached. We can use that. */
        if (count($this -> m_aParsed) >= 2)
        {
            return $this -> m_aParsed;
        }
        
        /* Set the payloadlength to the actual data length */
        $this -> m_nPayLoadLength = count($this -> m_aData);
        
        /* The frame will be stored in this array as bytes */
        $aBytes = array();
        
        /* The first byte... */
        $nByte = 0x0;
        
        /* If this frame is the final frame of a message, set the MSB to 1 */
        if ($this -> m_bFinal)
        {
            $nByte |= 0x80;
        }
        
        /* The next three bytes of the first byte are the bits reservers for extensions */
        $nByte |= ($this -> m_nRsv << 4);
        
        /* The final 4 bits of the first byte contains the frame opcode or type */
        $nByte |= $this -> m_nType;
        
        /* The first byte is fully parsed, store it in the result byte array */
        $aBytes[] = $nByte;
        
        /* The first bit of the second byte is the masking bit. If the frame is masked,
         * set it to 1 and set it to 0 otherwise. */
        if ($this -> m_bMasked)
        {
            $nByte = 0x80;
        }
        else
        {
            $nByte = 0x0;
        }
        
        /*
         * The next bytes are a bit more complicated, if the payload length is less then
         * 126 bytes, the length will be in the next 7 bits. If the payload length is larger
         * then 125 but smaller then OxFFFF the next 7 bits should be 126 and the actual length
         * is in the next 16 bits. If the payload length is larger then 0xFFFF then the 7 bits
         * should be 127 and the actual length is in the next 64 bits. The length is a handled
         * as a unsigned integer.
         */
        if ($this -> m_nPayLoadLength < 126)
        {
            $nByte |= $this -> m_nPayLoadLength;
            $aBytes[] = $nByte;
        }
        elseif ($this -> m_nPayLoadLength <= 0xFFFF)
        {
            $nByte |= 0x7E;
            $aBytes[] = $nByte;

            $aBytes = array_merge($aBytes, $this -> unpack($this -> m_nPayLoadLength, 2));
        }
        elseif ($this -> m_nPayLoadLength <= PHP_INT_MAX)
        {
            $nByte |= 0x7F;
            $aBytes[] = $nByte;
            $aBytes = array_merge($aBytes, $this -> unpack($this -> m_nPayLoadLength, 8));
        }
        else
        {
            return false;
        }

        /* If the masking key is set, mask the frame */
        if ($this -> m_bMasked)
        {
            /* Generate a random masking key if non already exists */
            if (count($this -> m_aMaskingKey) != 4)
            {
                $this -> m_aMaskingKey = self :: generateMaskingKey();
            }
            
            /* Add the masking key to the frame */
            $aBytes = array_merge($aBytes, $this -> m_aMaskingKey);
            
            /* mask the data */
            $this -> mask();
        }
        
        /* Add the data to the resulting byte stream (the frame) if there is any data*/
        if ($this -> m_nPayLoadLength != 0)
        {
            $aBytes = array_merge($aBytes, $this -> m_aData);
        }
        
        /* Store parsed frame in cache */
        $this -> m_aParsed = $aBytes;

        /* Return byte array */
        return $aBytes;

    }
    
    /**
     * This method will unmask the payload data using the masking key
     *
     * @return true if unmasking was succesfull, false otherwise
     */
    protected function unMask()
    {
        /* We need 4 bytes in the masking key */
        if (count($this -> m_aMaskingKey) == 4)
        {
            /* Unmasking uses the same algorithm as masking */
            $this -> mask();
            return true;
        }
        
        return false;
    }
    
    /**
     * This method will mask the payload data using the masking key.
     * If no masking key is set, a random key will be generated.
     */
    protected function mask()
    {
        /* Reset the cache */
        $this -> m_aParsed = array();
        
        /* Do we have a valid masking key? */
        if (count($this -> m_aMaskingKey) == 4)
        {
            /* If so, use that key */
            $aMaskingKey = $this -> m_aMaskingKey;
        }
        else
        {
            /* If not, generate one */
            $aMaskingKey = self :: generateMaskingKey();
        }

        /* Mask using XOR encryption */
        for ($i = 0; $i < count($this -> m_aData); $i++)
        {
           $this -> m_aData[$i] = $this -> m_aData[$i] ^ $aMaskingKey[$i % 4];
        }
    }
    
    /**
     * Data that was read from a socket is divided into bytes. When we need
     * to repack 4 bytes back into a 32 bit integer we have to merge these
     * bytes and do some bit shifting.
     *
     * @param array $aBytes Byte array to repack
     * @return int The resulting integer
     */
    protected function repack(array $aBytes)
    {
        $nResult = 0x0;
        
        for ($i = 0; $i < count($aBytes); $i++)
        {
            $nResult = $nResult << 8;
            $nResult += $aBytes[$i];
        }
        
        return $nResult;
    }
    
    /**
     * When we need to send integers larger then 8 bits we need to split them
     * into multiple bytes in order to send them. If you request more bytes then
     * are in the value, the singing bit will be used to form new bytes.
     *
     * @param int $nValue Value that needs to be split into bytes
     * @param int $nBytes Number of bytes in the resulting array
     * @return array The bytes array
     */
    protected function unpack($nValue, $nBytes)
    {
        $aResult = array();
        
        for ($i = 0; $i < $nBytes; $i++)
        {
            $aResult[] = $nValue & 0xFF;
            $nValue = $nValue >> 8;
        }
        $aResult = array_reverse($aResult);
        return $aResult;
    }
    
    /**
     * Use this method to set the payload data for a frame. Length
     * parameters and cache are updated.
     *
     * @param array $aData The array with payload data
     * @return boolean True if the new data is set, false otherwise
     */
    public function setData(array $aData)
    {
        if (self :: $m_nMaxLengthOut < count($aData))
        {
            /* Exceeding maximum frame length */
            return false;
        }
        
        /* Reset cache */
        $this -> m_aParsed = array();
    
        $this -> m_aData = $aData;
        
        $this -> m_nPayLoadLength = count($aData);
        
        return true;
    }
    
    /**
     * Getter for the payload data
     *
     * @return array The current payload data in this frame
     */
    public function getData()
    {
        return $this -> m_aData;
    }
    
    /**
     * Getter for the payload data
     *
     * @return array The current payload data in this frame
     */
    public function getType()
    {
        return $this -> m_nType;
    }
    
    /**
     * Getter for frame status. Frames can be read in smaller pieces. This
     * value will indicate whether the frame has been fully read.
     *
     * @return boolean True if the frame is complete, false otherwise
     */
    public function isComplete()
    {
        return $this -> m_bIsComplete;
    }
    
    /**
     * Getter for frame status as part of a full message. Messages are send as
     * smaller parts or frames. This value indicates whether this is the last
     * part or frame of a message.
     *
     * @return boolean True if this is the final frame for a message, false if there
     *                 are more frames to come.
     */
    public function isFinal()
    {
        return $this -> m_bFinal;
    }
    
    /**
     * Getter for the masking bit.
     *
     * @return boolean True if the frame is masked, false otherwise
     */
    public function isMasked()
    {
        return $this -> m_bMasked;
    }
    
    /**
     * Determins whether this frame is a control frame or not. Control frames
     * are closing, ping and pong frames. All other are data frames.
     *
     * @return boolean True if this is a control frame, false otherwise
     */
    public function isControlFrame()
    {
        return ($this -> m_nType == static :: TYPE_CLOSE || $this -> m_nType == static :: TYPE_PING ||
                $this -> m_nType == static :: TYPE_PONG);
    }

    /**
     * Getter for the maximum frame length. The frame length is the payload
     * length. It does not include the headers or masking key.
     *
     * @return int The maximum frame length
     */
    public static function getMaxLength()
    {
        return static :: $m_nMaxLengthOut;
    }
    
    /**
     * Setter for the maximum frame length. The frame length is the payload
     * length. It does not include the headers or masking key.
     *
     * @param int $nMaxLength The maximum frame length
     */
    public static function setMaxLength($nMaxLength)
    {
        static :: $m_nMaxLengthOut = $nMaxLength;
    }
    
    /**
     * Generates a random 4 byte maskink key for XOR encryption
     *
     * @return array Newly generated masking key
     */
    public static function generateMaskingKey()
    {
        $aMaskingKey = array();
        
        for ($i = 0; $i < 4; $i++)
        {
            $aMaskingKey[] = mt_rand(0, 0xFF);
        }
        
        return $aMaskingKey;
    }
}