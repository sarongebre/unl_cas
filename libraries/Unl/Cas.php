<?php

class Unl_Cas
{
    /**
     * Web service url
     * 
     * @var string
     */
    private $_serviceUrl;
    
    /**
     * CAS validation url
     *
     * @var string
     */
    private $_casUrl;
    
    /**
     * Username returned by cas validation request
     *
     * @var string
     */
    private $_username;
    
    /**
     * Cas ticket issued by cas login service
     *
     * @var string
     */
    private $_ticket;
    
    /**
     * Whether or not to set either the gateway or renew parameters in the login request.
     * 
     * @var int
     */
    private $_gatewayOrRenew;
    const PARAM_GATEWAY = 1;
    const PARAM_DEFAULT = 2;
    const PARAM_RENEW   = 3;
    
    /**
     * Session storage use to prevent infinite redirect loops when in gateway mode.
     * Do not use this directly, use $this->_session()
     * @var Zend_Session_Namespace|array
     */
    private $_session;
    
    /**
     * Cache used to store valid service tickets.
     * @var Zend_Cache_Core
     */
    private $_ticketCache;
    
    /**
     * Constructor
     *
     * @param string $serviceUrl
     * @param string $casValidateUrl
     * @param string $ticket
     * @param bool $forceLogin
     * @return void
     */
    public function __construct($serviceUrl, $casUrl, $ticket = null, $gatewayOrRenew = self::PARAM_DEFAULT)
    {
        $this->setServiceUrl($serviceUrl);
        $this->_casUrl = $casUrl;
        $this->_gatewayOrRenew = $gatewayOrRenew;
        if ($ticket) {
            $this->_ticket = $ticket;
        } elseif (! empty($_GET["ticket"])) {
            $this->_ticket = $_GET['ticket'];
        }
        try {
            $this->_session = new Zend_Session_Namespace(__CLASS__);
        } catch (Zend_Session_Exception $e) {
        	//Problem starting Zend_Session (probably because it was already started, use standard PHP sessions.
            if (!array_key_exists(__CLASS__, $_SESSION) || !is_array($_SESSION[__CLASS__])) {
                $_SESSION[__CLASS__] = array();
        	}
            $this->_session = NULL;
        }
    }
    
    /**
     * Set the service url
     *
     * @param string $serviceUrl
     */
    public function setServiceUrl($serviceUrl)
    {
        $this->_serviceUrl = $serviceUrl;
    }
    
    /**
     * Set the validation url
     *
     * @param string $validationUrl
     */
    public function setCasUrl($casUrl)
    {
        $this->_casUrl = $casUrl;
    }
    
    /**
     * Set the ticket to be validated
     *
     * @param string $ticket
     */
    public function setTicket($ticket)
    {
        $this->_ticket = $ticket;
    }
    
    /**
     * Returns authentication url
     *
     * @return string
     */
    public function getServiceUrl()
    {
        return $this->_serviceUrl;
    }
    
    /**
     * Returns validation service url
     *
     * @return string
     */
    public function getCasUrl()
    {
        return $this->_casUrl;
    }
    
    /**
     * Returns the username, if successfully authenticated
     *
     * @return string
     */
    public function getUsername()
    {
        return $this->_session('username');
    }
    
    /**
     * Returns the ticket to be validated
     *
     * @return string
     */
    public function getTicket()
    {
        return $this->_ticket;
    }
    
    /**
     * Sets the "renew" parameter in the login request, causing the CAS server
     * to _always_ ask for the user to authenticate.
     * 
     * @param bool $renew
     */
    public function setRenew($renew = TRUE)
    {
        if ($renew) {
            $this->_gatewayOrRenew = self::PARAM_RENEW;
        } else {
            $this->_gatewayOrRenew = self::PARAM_DEFAULT;
        }
    }
    
    /**
     * Returns whether or not the login request will have the "renew" parameter set.
     * @return bool
     */
    public function getRenew()
    {
        return ($this->_gatewayOrRenew == self::PARAM_RENEW);
    }
    
    /**
     * Alias to setRenew()
     * 
     * @deprecated
     * @param bool $forceLogin
     */
    public function setForceLogin($forceLogin)
    {
        return $this->setRenew($forceLogin);
    }
    
    /**
     * Alias to getRenew()
     * 
     * @deprecated
     * @return bool
     */
    public function getForceLogin()
    {
        return $this->_getRenew();
    }
    
    /**
     * Sets the "gateway" parameter in the login request, causing the CAS server
     * to _never_ ask for the user to authenticate.
     * @param bool $gateway
     */
    public function setGateway($gateway = TRUE)
    {
        if ($gateway) {
            $this->_gatewayOrRenew = self::PARAM_GATEWAY;
        } else {
            $this->_gatewayOrRenew = self::PARAM_DEFAULT;
        }
    }
    
    /**
     * Returns whether or not the login request will have the "gateway" parameter set.
     * @return bool
     */
    public function getGateway()
    {
        return ($this->_gatewayOrRenew == self::PARAM_GATEWAY);
    }
    
    /**
     * Returns the URL to the CAS login page.
     * Intended usage: $this->_redirect($cas->getLoginUrl());
     */
    public function getLoginUrl()
    {
        $location = $this->_casUrl . '/login?service=' . urlencode($this->_serviceUrl);
        switch ($this->_gatewayOrRenew) {
            case self::PARAM_GATEWAY:
                $location .= '&gateway=true';
                break;
            case self::PARAM_RENEW:
                $location .= '&renew=true';
                break;
        }
        return $location;
    }
    
    /**
     * Returns the URL to the CAS logout page.
     * Intended usage: $this->_redirect($cas->getLogoutUrl());
     */
    public function getLogoutUrl($returnUrl = '')
    {
        $url = $this->_casUrl . '/logout';
        if ($returnUrl) {
        	$url .= '?url=' . urlencode($returnUrl);
        }
        return $url;
    }

    /**
     * Generate Zend_Http request for the validation service and get the response
     *
     * @param string $ticket
     * @return bool
     */
    public function validateTicket($ticket = NULL)
    {
    	if (!$ticket) {
    		$ticket = $this->_ticket;
    	}
    	
        require_once ('Zend/Http/Client.php');
        $client = new Zend_Http_Client($this->_casUrl . '/serviceValidate?service=' . urlencode($this->_serviceUrl) . '&ticket=' . $ticket);
        $response = $client->request();
        if ($response->isSuccessful() && $this->_parseResponse($response->getBody())) {
            $this->_addValidTicket($ticket);
            $this->_session('ticket', $ticket);
            return true;
        }
        return false;
    }
    
    /**
     * Parse the Zend_Http response, determine success.
     *
     * @param string $response
     * @return bool
     */
    private function _parseResponse($response)
    {
        $xml = new DOMDocument();
        if ($xml->loadXML($response)) {
            if ($success = $xml->getElementsByTagName('authenticationSuccess')) {
                if ($success->length > 0 && $uid = $success->item(0)->getElementsByTagName('user')) {
                    $this->_session('username', $uid->item(0)->nodeValue);
                    return true;
                }
            }
        }
        return false;
    }

    private function _addValidTicket($ticket)
    {
        $this->_getTicketCache()->save(time(), hash('sha512', $ticket));
    }
    
    private function _removeValidTicket($ticket)
    {
        $this->_getTicketCache()->remove(hash('sha512', $ticket));
    }
    
    private function _isStillValidTicket($ticket)
    {
        return (bool) ($this->_getTicketCache()->load(hash('sha512', $ticket)));
    }
    
    private function _getTicketCache()
    {
        if (!$this->_ticketCache) {
            $cache_dir = session_save_path();
            if (!$cache_dir) {
                $cache_dir = sys_get_temp_dir();
            }
            $frontendOptions = array(
                'lifetime' => 60*60,
                'automatic_serialization' => TRUE
            );
            $backendOptions = array(
                'cache_dir' => $cache_dir,
                'file_locking' => FALSE
            );
            $this->_ticketCache = Zend_Cache::factory('Core', 'File', $frontendOptions, $backendOptions);
        }
        return $this->_ticketCache;
    }
    
    public function setTicketCache(Zend_Cache_Core $cache)
    {
        $this->_ticketCache = $cache;
    }
    
    public function setTicketLifetime($lifetime)
    {
        $this->_getTicketCache()->setLifetime($lifetime);
    }
    
    public function isTicketExpired()
    {
        return !$this->_isStillValidTicket($this->_session('ticket'));
    }
    
    public function handleLogoutRequest($saml)
    {   
        $request = new DOMDocument();
        if (!$request->loadXML($saml)) {
            return;
        }
        $ticketNodes = $request->getElementsByTagName('SessionIndex');
        if ($ticketNodes->length == 0) {
            return;
        }
        $ticket = $ticketNodes->item(0)->textContent;
        $this->_removeValidTicket($ticket);
        exit;
    }
    
    public function destroySession()
    {
        $this->_removeValidTicket($this->_session('ticket'));
        $this->_session('ticket', NULL);
        $this->_session('username', NULL);
    }

    // Wrapper to use either Zend sessions or native PHP sessions
    protected function _session($key, $val = NULL)
    {
        if ($this->_session instanceof Zend_Session_Namespace) {
            if (func_num_args() == 2) {
                $this->_session->$key = $val;
            } else {
                return $this->_session->$key;
            }
        } else {
            if (func_num_args() == 2) {
                $_SESSION[__CLASS__][$key] = $val;
            } else {
                if (!isset($_SESSION[__CLASS__][$key])) {
                    return NULL;
                }
                return $_SESSION[__CLASS__][$key];
            }
        }
    }
}

