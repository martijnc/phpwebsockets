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

namespace App\Web;

class HttpCookie
{

    /**
     * The cookie's name...
     *
     * @var string The cookie's name
     */
    protected $m_sName;
    
    /**
     * The value for the cookie.
     *
     * @var string The cookie's value
     */
    protected $m_sValue;
    
    /** 
     * The max age is a unix timstamp that says when the cookie should expire and
     * can be deleted. This is an optional part.
     *
     * @var int The time at which the cookie becomes invalid
     */
    protected $m_nMaxAge;
    
    /**
     * @var string The domain were the cookie is valid (optional)
     */
    protected $m_sDomain;
    
    /**
     * @var string The cookie path (optional)
     */
    protected $m_sPath;
    
    /**
     * @var boolean True if it is a secure cookie (send only over https)
     */
    protected $m_bSecure;
    
    /**
     * @var boolean True if the cookie is httponly (not accesible through scripts)
     */
    protected $m_bHttpOnly;
    
    /**
     * The constructor for the cookie class
     *
     * @param string $sName The cookie's name
     * @param string $sValue The cookie's value
     * @param int $nMaxAge Unix timestamp with expiration date
     * @param string $sPath The path in which the cookie is valid
     * @param string $sDoman The domain where the cookie is valid
     * @param boolean $bSecure True if this is a https only cookie, false otherwise
     * @param boolean $bHttpOnly True if the cookie is http only, false otherwise
     */
    public function __construct($sName, $sValue, $nMaxAge = null, $sPath = null, $sDomain = null, $bSecure = null, $bHttpOnly = null)
    {
        $this -> m_sName       = $sName;
        $this -> m_sValue      = $sValue;
        $this -> m_nMaxAge     = $nMaxAge;
        $this -> m_sPath       = $sPath;
        $this -> m_sDomain     = $sDomain;
        $this -> m_bSecure     = $bSecure;
    }
    
    /**
     * This method will form a valid (RFC 6265) Set-Cookie value for this cookie. 
     *
     * @return string A string that can be used for the Set-Cookie header
     */
    public function toSetString()
    {
        /* Start with the name and value */
        $sSetString = $this -> m_sName . ' = ' . urlencode($this -> m_sValue) . ';';
        
        /* And add the optional parameters with their respective values */
        if ($this -> m_nMaxAge !== null) {
            $sSetString .= ' Max-Age = ' . $this -> m_nMaxAge . ';';
        }
        
        if ($this -> m_sPath !== null) {
            $sSetString .= ' Path = ' . $this -> m_sPath . ';';
        }
        
        if ($this -> m_sDomain !== null) {
            $sSetString .= ' Domain = ' . $this -> m_sDomain . ';';
        }
        
        /* And the extra cookie options */
        if ($this -> m_bSecure !== null) {
            $sSetString .= ' Secure;';
        }
        
        if ($this -> m_bHttpOnly !== null) {
            $sSetString .= ' httponly;';
        }
        
        return $sSetString;
    }
    
    /** 
     * This static method takes a string that is send from a user-agent
     * containing the cookie information and parses it into a associative array.
     * Each key-value pair represents a cookie's name and value.
     *
     * @return array The parsed cookies
     */
    public static function parseCookies($sHeaderString)
    {
        /* Different cookies are seperated by a semicolon */
        $aCookies = explode(';', $sHeaderString);
        
        $aParsed = array();
        
        /* Loop through the cookies, split the key and value of each cookie */
        foreach ($aCookies as $sCookie) {
            $aParts = explode('=', $sCookie, 2);
            
            $aParsed[trim($aParts[0])] = urldecode(trim($aParts[1]));
        }
        
        return $aParsed;
    }
}

