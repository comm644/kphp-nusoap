<?php


/**
 *
 * [nu]soapclient higher level class for easy usage.
 *
 * usage:
 *
 * // instantiate client with server info
 * $soapclient = new nusoap_client( string path [ ,mixed wsdl] );
 *
 * // call method, get results
 * echo $soapclient->call( string methodname [ ,array parameters] );
 *
 * // bye bye client
 * $soapclient = null;
 *
 * @author   Dietrich Ayala <dietrich@ganx4.com>
 * @author   Scott Nichol <snichol@users.sourceforge.net>
 * @version  $Id: nusoap.php,v 1.123 2010/04/26 20:15:08 snichol Exp $
 * @access   public
 */
class nusoap_client extends nusoap_base
{

	public string $username = '';                // Username for HTTP authentication
	public string $password = '';                // Password for HTTP authentication
	public string $authtype = '';                // Type of HTTP authentication
	public array $certRequest = array();        // Certificate for HTTP SSL authentication
	/** @var mixed */
	public $requestHeaders = false;    // SOAP headers in request (text)
	public $responseHeaders = '';        // SOAP headers from response (incomplete namespace resolution) (text)
	/** @var mixed|null */
	public $responseHeader = null;        // SOAP Header from response (parsed)
	public $document = '';                // SOAP body response portion (incomplete namespace resolution) (text)
	public string $endpoint;
	public $forceEndpoint = '';        // overrides WSDL endpoint

	public nusoap_connection $connection;

	public string $portName = '';                // port name to use in WSDL
	public string $xml_encoding = '';            // character set encoding of incoming (response) messages
	public string $http_encoding = '';
	public string $endpointType = '';            // soap|wsdl, empty for WSDL initialization error
	public ?nusoap_transport_http $persistentConnection = null;
	public $defaultRpcParams = false;    // This is no longer used
	public string $request = '';                // HTTP request
	public string $response = '';                // HTTP response
	public ?string $responseData = null;            // SOAP payload of response
	/** @var mixed[][]  */
	public $cookies = array();            // Cookies from response or for request
	public bool $decode_utf8 = true;        // toggles whether the parser decodes element content w/ utf8_decode()
	/** @var mixed[][] */
	public array $operations = array();        // WSDL operations, empty for WSDL initialization error
	/** @var mixed[] */
	public array $curl_options = array();    // User-specified cURL options
	public string $bindingType = '';            // WSDL operation binding type
	public bool $use_curl = false;            // whether to always try to use cURL

	/*
	 * fault related variables
	 */
	public bool $fault = false;
	public string $faultcode = '';
	public string $faultstring = '';
	/** @var mixed  */
	public $faultdetail = '';

	private ?nusoap_wsdl $wsdl = null;
	private ?string $wsdlFile = null;
	/** @var mixed */
	private $return;
	/** @var mixed[] */
	private array $opData;
	private string $operation;

	/**
	 * constructor
	 *
	 * @param    string $endpoint SOAP server or WSDL URL (string), or wsdl instance (object)
	 * @param    mixed $wsdl optional, set to 'wsdl' or true if using WSDL
	 * @param    string $portName optional portName in WSDL document
	 * @access   public
	 */
	public function __construct(string $endpoint, $wsdl = false, string $portName = '', ?nusoap_connection $conn=null)
	{
		parent::__construct();
		if (!$conn) $conn =  new nusoap_connection();

		$this->connection = $conn;
		$this->endpoint = $endpoint;
		$this->portName = $portName;

		$this->debug("ctor wsdl=$wsdl timeout={$conn->timeout} response_timeout={$conn->response_timeout}");
		$this->appendDebug('endpoint=' . $this->varDump($endpoint));

		// make values
		if ($wsdl) {
// strict types of KPHP - we cant combine object|mixed
//			if (is_object($endpoint) && ($endpoint instanceof nusoap_wsdl)) {
//				/** @var nusoap_wsdl $endpointAsWsdl */
//				$endpointAsWsdl = $endpoint;
//				$this->wsdl = $endpointAsWsdl;
//				$this->endpoint = $this->wsdl->wsdl;
//				$this->wsdlFile = $this->wsdl->wsdl;
//				$this->debug('existing wsdl instance created from ' . $this->endpoint);
//				$this->checkWSDL();
//			} else
			{
				$this->wsdlFile = (string)$this->endpoint;
				$this->wsdl = null;
				$this->debug('will use lazy evaluation of wsdl from ' . $this->endpoint);
			}
			$this->endpointType = 'wsdl';
		} else {
			$this->debug("instantiate SOAP with endpoint at $endpoint");
			$this->endpointType = 'soap';
		}
	}

	/**
	 * calls method, returns PHP native type
	 *
	 * @param    string $operation SOAP server URL or path
	 * @param    mixed $params An array, associative or simple, of the parameters
	 *                          for the method call, or a string that is the XML
	 *                          for the call.  For rpc style, this call will
	 *                          wrap the XML in a tag named after the method, as
	 *                          well as the SOAP Envelope and Body.  For document
	 *                          style, this will only wrap with the Envelope and Body.
	 *                          IMPORTANT: when using an array with document style,
	 *                          in which case there
	 *                         is really one parameter, the root of the fragment
	 *                         used in the call, which encloses what programmers
	 *                         normally think of parameters.  A parameter array
	 *                         *must* include the wrapper.
	 * @param    string $namespace optional method namespace (WSDL can override)
	 * @param    string $soapAction optional SOAPAction value (WSDL can override)
	 * @param    mixed $headers optional string of XML with SOAP header content, or array of soapval objects for SOAP headers, or associative array
	 * @param    bool|null $rpcParams optional (no longer used)
	 * @param    string $style optional (rpc|document) the style to use when serializing parameters (WSDL can override)
	 * @param    string $use optional (encoded|literal) the use when serializing parameters (WSDL can override)
	 * @return    mixed    response from SOAP call, normally an associative array mirroring the structure of the XML response, false for certain fatal errors
	 * @access   public
	 */
	public function call(string $operation, $params = array(),
	                     string $namespace = 'http://tempuri.org',
	                     string $soapAction = '',
	                     $headers = false,
	                     ?bool $rpcParams = null, string$style = 'rpc', string $use = 'encoded')
	{
		$this->operation = $operation;
		$this->fault = false;
		$this->setError('');
		$this->request = '';
		$this->response = '';
		$this->responseData = '';
		$this->faultstring = '';
		$this->faultcode = '';
		$this->opData = array();

		$this->debug("call: operation=$operation, namespace=$namespace, soapAction=$soapAction, rpcParams=$rpcParams, style=$style, use=$use, endpointType=$this->endpointType");
		$this->appendDebug('params=' . $this->varDump($params));
		$this->appendDebug('headers=' . $this->varDump($headers));
		if ($headers) {
			$this->requestHeaders = $headers;
		}
		if (($this->endpointType === 'wsdl') && is_null($this->wsdl)) {
			$this->loadWSDL();
			if ($this->getError()) {
				return false;
			}
		}
		// serialize parameters
		$opData = $this->getOperationData($operation);
		if (($this->endpointType === 'wsdl') && $opData) {
			// use WSDL for operation
			$this->opData = $opData;
			$this->debug("found operation");
			$this->appendDebug('opData=' . $this->varDump($opData));
			if (isset($opData['soapAction'])) {
				$soapAction = (string)$opData['soapAction'];
			}
			if (!$this->forceEndpoint) {
				$this->endpoint = (string)$opData['endpoint'];
			} else {
				$this->endpoint = $this->forceEndpoint;
			}
			$namespace = isset($opData['input']['namespace']) ? (string)$opData['input']['namespace'] : $namespace;
			$style = (string)$opData['style'];
			$use = (string)$opData['input']['use'];
			// add ns to ns array
			if (!!$namespace && !isset($this->wsdl->namespaces[$namespace])) {
				$nsPrefix = 'ns' . rand(1000, 9999);
				$this->wsdl->namespaces[$nsPrefix] = $namespace;
			}
			$nsPrefix = $this->wsdl->getPrefixFromNamespace($namespace);
			// serialize payload
			if (is_string($params)) {
				$this->debug("serializing param string for WSDL operation $operation");
				$payload = $params;
			} else if (is_array($params)) {
				$this->debug("serializing param array for WSDL operation $operation");
				$payload = $this->wsdl->serializeRPCParameters($operation, 'input', (array)$params, $this->bindingType);
			} else {
				$this->debug('params must be array or string');
				$this->setError('params must be array or string');
				return false;
			}
			$usedNamespaces = $this->wsdl->usedNamespaces;
			if (isset($opData['input']['encodingStyle'])) {
				$encodingStyle = $opData['input']['encodingStyle'];
			} else {
				$encodingStyle = '';
			}
			$this->appendDebug($this->wsdl->getDebug());
			$this->wsdl->clearDebug();
			$errStr = $this->wsdl->getError();
			if ($errStr) {
				$this->debug('got wsdl error: ' . $errStr);
				$this->setError('wsdl error: ' . $errStr);
				return false;
			}
		} else if ($this->endpointType === 'wsdl') {
			// operation not in WSDL
			$this->appendDebug($this->wsdl->getDebug());
			$this->wsdl->clearDebug();
			$this->setError('operation ' . $operation . ' not present in WSDL.');
			$this->debug("operation '$operation' not present in WSDL.");
			return false;
		} else {
			// no WSDL
			//$this->namespaces['ns1'] = $namespace;
			$nsPrefix = 'ns' . rand(1000, 9999);
			// serialize
			$payload = '';
			if (is_string($params)) {
				$this->debug("serializing param string for operation $operation");
				$payload = $params;
			} else if (is_array($params)) {
				$this->debug("serializing param array for operation $operation");
				foreach ($params as $k => $v) {
					$payload .= $this->serialize_val((string)$v, (string)$k, '', '', '', [], $use);
				}
			} else {
				$this->debug('params must be array or string');
				$this->setError('params must be array or string');
				return false;
			}
			$usedNamespaces = array();
			if ($use === 'encoded') {
				$encodingStyle = 'http://schemas.xmlsoap.org/soap/encoding/';
			} else {
				$encodingStyle = '';
			}
		}
		// wrap RPC calls with method element
		if ($style === 'rpc') {
			if ($use === 'literal') {
				$this->debug("wrapping RPC request with literal method element");
				if ($namespace) {
					// http://www.ws-i.org/Profiles/BasicProfile-1.1-2004-08-24.html R2735 says rpc/literal accessor elements should not be in a namespace
					$payload = "<$nsPrefix:$operation xmlns:$nsPrefix=\"$namespace\">" .
						$payload .
						"</$nsPrefix:$operation>";
				} else {
					$payload = "<$operation>" . $payload . "</$operation>";
				}
			} else {
				$this->debug("wrapping RPC request with encoded method element");
				if ($namespace) {
					$payload = "<$nsPrefix:$operation xmlns:$nsPrefix=\"$namespace\">" .
						$payload .
						"</$nsPrefix:$operation>";
				} else {
					$payload = "<$operation>" .
						$payload .
						"</$operation>";
				}
			}
		}
		// serialize envelope
		$soapmsg = $this->serializeEnvelope((string)$payload, $this->requestHeaders, $usedNamespaces, $style, $use, (string)$encodingStyle);
		$this->debug("endpoint=$this->endpoint, soapAction=$soapAction, namespace=$namespace, style=$style, use=$use, encodingStyle=$encodingStyle");
		$this->debug('SOAP message length=' . strlen($soapmsg) . ' contents (max 1000 bytes)=' . substr($soapmsg, 0, 1000));
		// send
		$return = $this->send($this->getHTTPBody($soapmsg), $soapAction,
			$this->connection->timeout,
			$this->connection->response_timeout);

		$errStr = $this->getError();
		if ($errStr) {
			$this->debug('Error: ' . $errStr);
			return false;
		}

		$this->return = $return;
		$this->debug('sent message successfully and got a(n) ' . gettype($return));
		$this->appendDebug('return=' . $this->varDump($return));

		// fault?
		if (is_array($return) && isset($return['faultcode'])) {
			$this->debug('got fault');
			$this->setError($return['faultcode'] . ': ' . $return['faultstring']);
			$this->fault = true;
			foreach ($return as $k => $v) {
#ifndef KPHP
				$this->$k = $v;
#endif
				if (is_array($v)) {
					$this->debug("$k = " . json_encode($v));
				} else {
					$this->debug("$k = $v<br>");
				}
			}
			return $return;
		}

		if ($style === 'document') {
			// NOTE: if the response is defined to have multiple parts (i.e. unwrapped),
			// we are only going to return the first part here...sorry about that
			return $return;
		}

		// array of return values
		if (!is_array($return)) {
			return "";
		}

		// multiple 'out' parameters, which we return wrapped up
		// in the array
		if (count($return) > 1) {
			return $return;
		}
		// single 'out' parameter (normally the return value)
		$return = array_shift($return);
		$this->debug('return shifted value: ');
		$this->appendDebug($this->varDump($return));
		return $return;
		// nothing returned (ie, echoVoid)
	}

	/**
	 * check WSDL passed as an instance or pulled from an endpoint
	 */
	protected function checkWSDL():void
	{
		$this->appendDebug($this->wsdl->getDebug());
		$this->wsdl->clearDebug();
		$this->debug('checkWSDL');
		// catch errors
		$errStr = $this->wsdl->getError();
		if ($errStr) {
			$this->appendDebug($this->wsdl->getDebug());
			$this->wsdl->clearDebug();
			$this->debug('got wsdl error: ' . $errStr);
			$this->setError('wsdl error: ' . $errStr);
			return;
		}

		$this->operations = $this->wsdl->getOperations($this->portName, 'soap');
		if ($this->operations) {
			$this->appendDebug($this->wsdl->getDebug());
			$this->wsdl->clearDebug();
			$this->bindingType = 'soap';
			$this->debug('got ' . count($this->operations) . ' operations from wsdl ' . $this->wsdlFile . ' for binding type ' . $this->bindingType);
			return;

		}
		$this->operations = $this->wsdl->getOperations($this->portName, 'soap12');
		if ($this->operations) {
			$this->appendDebug($this->wsdl->getDebug());
			$this->wsdl->clearDebug();
			$this->bindingType = 'soap12';
			$this->debug('got ' . count($this->operations) . ' operations from wsdl ' . $this->wsdlFile . ' for binding type ' . $this->bindingType);
			$this->debug('**************** WARNING: SOAP 1.2 BINDING *****************');
			return;
		}

		$this->appendDebug($this->wsdl->getDebug());
		$this->wsdl->clearDebug();
		$this->debug('getOperations returned false');
		$this->setError('no operations defined in the WSDL document!');
	}

	/**
	 * instantiate wsdl object and parse wsdl file
	 *
	 * @access    public
	 */
	public function loadWSDL()
	{
		$this->debug('instantiating wsdl class with doc: ' . $this->wsdlFile);
		$this->wsdl = new nusoap_wsdl('', $this->connection);

		$this->wsdl->setCredentials($this->username, $this->password, $this->authtype, $this->certRequest);
		$this->wsdl->fetchWSDL((string)$this->wsdlFile);
		$this->checkWSDL();
	}

	/**
	 * get available data pertaining to an operation
	 *
	 * @param    string $operation operation name
	 * @return    mixed[]|null array of data pertaining to the operation
	 * @access   public
	 */
	public function getOperationData($operation):?array
	{
		if (($this->endpointType === 'wsdl') && is_null($this->wsdl)) {
			$this->loadWSDL();
			if ($this->getError()) {
				return null;
			}
		}
		if (isset($this->operations[$operation])) {
			return $this->operations[$operation];
		}
		$this->debug("No data for operation: $operation");
		return null;
	}

	/**
	 * send the SOAP message
	 *
	 * Note: if the operation has multiple return values
	 * the return value of this method will be an array
	 * of those values.
	 *
	 * @param    string $msg a SOAPx4 soapmsg object
	 * @param    string $soapaction SOAPAction value
	 * @param    int $timeout set connection timeout in seconds
	 * @param    int $response_timeout set response timeout in seconds
	 * @return    mixed native PHP types.
	 * @access   private
	 */
	public function send(string $msg, string $soapaction = '', int $timeout = 0, int $response_timeout = 30)
	{
		$this->checkCookies();
		// detect transport
		switch (true) {
			// http(s)
			case preg_match('/^http/', $this->endpoint):
				$this->debug('transporting via HTTP');
				if ($this->persistentConnection) {
					$http = $this->persistentConnection;
				} else {
					$http = new nusoap_transport_http((string)$this->endpoint, $this->curl_options, $this->use_curl);
					if ($this->persistentConnection) {
						$http->usePersistentConnection();
					}
				}
				$http->setContentType($this->getHTTPContentType(), $this->getHTTPContentTypeCharset());
				$http->setSOAPAction($soapaction);
				if ($this->connection->proxyhost && $this->connection->proxyport) {
					$http->setProxy($this->connection);
				}
				if ($this->authtype != '') {
					$http->setCredentials($this->username, $this->password, $this->authtype, array(), $this->certRequest);
				}
				if ($this->http_encoding != '') {
					$http->setEncoding($this->http_encoding);
				}
				$this->debug('sending message, length=' . strlen($msg));
				if (preg_match('/^http:/', $this->endpoint)) {
					//if(strpos($this->endpoint,'http:')){
					$this->responseData = $http->send($msg, $timeout, $response_timeout, $this->cookies);
				} else if (preg_match('/^https/', $this->endpoint)) {
					//} elseif(strpos($this->endpoint,'https:')){
					//if(phpversion() == '4.3.0-dev'){
					//$response = $http->send($msg,$timeout,$response_timeout);
					//$this->request = $http->outgoing_payload;
					//$this->response = $http->incoming_payload;
					//} else
					$this->responseData = $http->sendHTTPS($msg, $timeout, $response_timeout, $this->cookies);
				} else {
					$this->setError('no http/s in endpoint url');
				}
				$this->request = $http->outgoing_payload;
				$this->response = (string)$http->incoming_payload;
				$this->appendDebug($http->getDebug());
				$this->UpdateCookies($http->incoming_cookies);

				// save transport object if using persistent connections
				if ($this->persistentConnection) {
					$http->clearDebug();
					if (!$this->persistentConnection) {
						$this->persistentConnection = $http;
					}
				}

				$err = $http->getError();
				if ($err) {
					$this->setError('HTTP Error: ' . $err);
					return false;
				}

				if ($this->getError()) {
					return false;
				}

				$this->debug('got response, length=' . strlen($this->responseData) . ' type=' . $http->incoming_headers['content-type']);
				return $this->parseResponse($http->incoming_headers, (string)$this->responseData);

			default:
				$this->setError('no transport found, or selected transport is not yet supported!');
				return false;
		}
	}

	/**
	 * processes SOAP message returned from server
	 *
	 * @param    string[] $headers The HTTP headers
	 * @param    string $data unprocessed response data from server
	 * @return    mixed    value of the message, decoded into a PHP type
	 * @access   private
	 */
	public function parseResponse($headers, $data)
	{
		$this->debug('Entering parseResponse() for data of length ' . strlen($data) . ' headers:');
		$this->appendDebug($this->varDump($headers));
		if (!isset($headers['content-type'])) {
			$this->setError('Response not of type ' . $this->contentType . ' (no content-type header)');
			return false;
		}
		if (!strstr($headers['content-type'], $this->contentType)) {
			$this->setError('Response not of type ' . $this->contentType . ': ' . $headers['content-type']);
			return false;
		}
		if (strpos($headers['content-type'], '=')) {
			$enc = str_replace('"', '', substr(strstr($headers["content-type"], '='), 1));
			$this->debug('Got response encoding: ' . $enc);
			if (preg_match('/^(ISO-8859-1|US-ASCII|UTF-8)$/i', $enc)) {
				$this->xml_encoding = strtoupper($enc);
			} else {
				$this->xml_encoding = 'US-ASCII';
			}
		} else {
			// should be US-ASCII for HTTP 1.0 or ISO-8859-1 for HTTP 1.1
			$this->xml_encoding = 'ISO-8859-1';
		}
		$this->debug('Use encoding: ' . $this->xml_encoding . ' when creating nusoap_parser');
		/** @var nusoap_parser|null $parser */
		$parser = new nusoap_parser($data, $this->xml_encoding, $this->decode_utf8);
		// add parser debug data to our debug
		$this->appendDebug($parser->getDebug());
		// if parse errors
		$errstr = $parser->getError();
		if ($errstr) {
			$this->setError($errstr);
			// destroy the parser object
			$parser = null;
			return false;
		}

		// get SOAP headers
		$this->responseHeaders = $parser->getHeaders();
		// get SOAP headers
		$this->responseHeader = $parser->get_soapheader();
		// get decoded message
		$return = $parser->get_soapbody();
		// add document for doclit support
		$this->document = $parser->document;
		// destroy the parser object
		$parser = null;
		// return decode message
		return $return;
	}

	/**
	 * sets user-specified cURL options
	 *
	 * @param    mixed $option The cURL option (always integer?)
	 * @param    mixed $value The cURL option value
	 * @access   public
	 */
	public function setCurlOption($option, $value)
	{
		$this->debug("setCurlOption option=$option, value=");
		$this->appendDebug($this->varDump($value));
		$this->curl_options[$option] = $value;
	}

	/**
	 * sets the SOAP endpoint, which can override WSDL
	 *
	 * @param    string $endpoint The endpoint URL to use, or empty string or false to prevent override
	 * @access   public
	 */
	public function setEndpoint($endpoint)
	{
		$this->debug("setEndpoint(\"$endpoint\")");
		$this->forceEndpoint = $endpoint;
	}

	/**
	 * set the SOAP headers
	 *
	 * @param    mixed $headers String of XML with SOAP header content, or array of soapval objects for SOAP headers
	 * @access   public
	 */
	public function setHeaders($headers)
	{
		$this->debug("setHeaders headers=");
		$this->appendDebug($this->varDump($headers));
		$this->requestHeaders = $headers;
	}

	/**
	 * get the SOAP response headers (namespace resolution incomplete)
	 *
	 * @return    string
	 * @access   public
	 */
	public function getHeaders()
	{
		return $this->responseHeaders;
	}

	/**
	 * get the SOAP response Header (parsed)
	 *
	 * @return    mixed|null
	 * @access   public
	 */
	public function getHeader()
	{
		return $this->responseHeader;
	}

	/** set proxy info here */
	public function setHTTPProxy(nusoap_connection $connection):void
	{
		$this->connection = $connection;
	}

	/**
	 * if authenticating, set user credentials here
	 *
	 * @param    string $username
	 * @param    string $password
	 * @param    string $authtype (basic|digest|certificate|ntlm)
	 * @param    string[] $certRequest (keys must be cainfofile (optional), sslcertfile, sslkeyfile, passphrase, verifypeer (optional), verifyhost (optional): see corresponding options in cURL docs)
	 * @access   public
	 */
	public function setCredentials(string $username, string $password,
	                               string $authtype = 'basic', array $certRequest = array())
	{
		$this->debug("setCredentials username=$username authtype=$authtype certRequest=");
		$this->appendDebug($this->varDump($certRequest));
		$this->username = $username;
		$this->password = $password;
		$this->authtype = $authtype;
		$this->certRequest = $certRequest;
	}

	/**
	 * use HTTP encoding
	 *
	 * @param    string $enc HTTP encoding
	 * @access   public
	 */
	public function setHTTPEncoding($enc = 'gzip, deflate')
	{
		$this->debug("setHTTPEncoding(\"$enc\")");
		$this->http_encoding = $enc;
	}

	/**
	 * Set whether to try to use cURL connections if possible
	 *
	 * @param    bool $use Whether to try to use cURL
	 * @access   public
	 */
	public function setUseCURL(bool $use)
	{
		$this->debug("setUseCURL($use)");
		$this->use_curl = $use;
	}
//
//	/**
//	 * use HTTP persistent connections if possible
//	 *
//	 * @access   public
//	 */
//	public function useHTTPPersistentConnection()
//	{
//		$this->debug("useHTTPPersistentConnection");
//		$this->persistentConnection = true;
//	}

	/**
	 * gets the default RPC parameter setting.
	 * If true, default is that call params are like RPC even for document style.
	 * Each call() can override this value.
	 *
	 * This is no longer used.
	 *
	 * @return bool
	 * @access public
	 * @deprecated
	 */
	public function getDefaultRpcParams():bool
	{
		return $this->defaultRpcParams;
	}

	/**
	 * sets the default RPC parameter setting.
	 * If true, default is that call params are like RPC even for document style
	 * Each call() can override this value.
	 *
	 * This is no longer used.
	 *
	 * @param    bool $rpcParams
	 * @access public
	 * @deprecated
	 */
	public function setDefaultRpcParams($rpcParams)
	{
		$this->defaultRpcParams = $rpcParams;
	}
#ifndef KPHP
	/**
	 * dynamically creates an instance of a proxy class,
	 * allowing user to directly call methods from wsdl
	 *
	 * @return   object soap_proxy object
	 * @access   public
	 */
	public function getProxy()
	{
		$r = rand();
		$evalStr = $this->_getProxyClassCode((string)$r);
		//$this->debug("proxy class: $evalStr");
		if ($this->getError()) {
			$this->debug("Error from _getProxyClassCode, so return null");
			return null;
		}
		// eval the class
		eval($evalStr);
		// instantiate proxy object
		$className = "nusoap_proxy_$r";
		/** @var nusoap_client $proxy */
		$proxy = new $className('');
		// transfer current wsdl data to the proxy thereby avoiding parsing the wsdl twice
		$proxy->endpointType = 'wsdl';
		$proxy->wsdlFile = $this->wsdlFile;
		$proxy->wsdl = $this->wsdl;
		$proxy->operations = $this->operations;
		$proxy->defaultRpcParams = $this->defaultRpcParams;
		// transfer other state
		$proxy->soap_defencoding = $this->soap_defencoding;
		$proxy->username = $this->username;
		$proxy->password = $this->password;
		$proxy->authtype = $this->authtype;
		$proxy->certRequest = $this->certRequest;
		$proxy->requestHeaders = $this->requestHeaders;
		$proxy->endpoint = $this->endpoint;
		$proxy->forceEndpoint = $this->forceEndpoint;
		$proxy->proxyhost = $this->connection->proxyhost;
		$proxy->proxyport = $this->connection->proxyport;
		$proxy->proxyusername = $this->connection->proxyusername;
		$proxy->proxypassword = $this->connection->proxypassword;
		$proxy->http_encoding = $this->http_encoding;
		$proxy->timeout = $this->connection->timeout;
		$proxy->response_timeout = $this->connection->response_timeout;
		$proxy->persistentConnection = &$this->persistentConnection;
		$proxy->decode_utf8 = $this->decode_utf8;
		$proxy->curl_options = $this->curl_options;
		$proxy->bindingType = $this->bindingType;
		$proxy->use_curl = $this->use_curl;
		return $proxy;
	}

	/**
	 * dynamically creates proxy class code
	 *
	 * @return   string PHP/NuSOAP code for the proxy class
	 * @access   private
	 */
	public function _getProxyClassCode(string $r)
	{
		$this->debug("in getProxy endpointType=$this->endpointType");
		$this->appendDebug("wsdl=" . $this->varDump(get_object_vars($this->wsdl)));
		if ($this->endpointType !== 'wsdl') {
			$evalStr = 'A proxy can only be created for a WSDL client';
			$this->setError($evalStr);
			$evalStr = "echo \"$evalStr\";";
			return $evalStr;
		}
		if (($this->endpointType === 'wsdl') && is_null($this->wsdl)) {
			$this->loadWSDL();
			if ($this->getError()) {
				return "echo \"" . $this->getError() . "\";";
			}
		}
		$evalStr = '';
		foreach ($this->operations as $operation => $opData) {
			if ($operation != '') {
				// create param string and param comment string
				if (sizeof($opData['input']['parts']) > 0) {
					$paramStr = '';
					$paramArrayStr = '';
					$paramCommentStr = '';
					foreach ($opData['input']['parts'] as $name => $type) {
						$paramStr .= "\$$name, ";
						$paramArrayStr .= "'$name' => \$$name, ";
						$paramCommentStr .= "$type \$$name, ";
					}
					$paramStr = substr($paramStr, 0, strlen($paramStr) - 2);
					$paramArrayStr = substr($paramArrayStr, 0, strlen($paramArrayStr) - 2);
					$paramCommentStr = substr($paramCommentStr, 0, strlen($paramCommentStr) - 2);
				} else {
					$paramStr = '';
					$paramArrayStr = '';
					$paramCommentStr = 'void';
				}
				$opData['namespace'] = !isset($opData['namespace']) ? 'http://testuri.com' : $opData['namespace'];
				$evalStr .= "// $paramCommentStr
	function " . str_replace('.', '__', $operation) . "($paramStr) {
		\$params = array($paramArrayStr);
		return \$this->call('$operation', \$params, '" . $opData['namespace'] . "', '" . (isset($opData['soapAction']) ? $opData['soapAction'] : '') . "');
	}
	";
				$paramStr ='';
				$paramCommentStr ='';
			}
		}
		$evalStr = 'class nusoap_proxy_' . $r . ' extends nusoap_client {
	' . $evalStr . '
}';
		return $evalStr;
	}

	/**
	 * dynamically creates proxy class code
	 *
	 * @return   string PHP/NuSOAP code for the proxy class
	 * @access   public
	 */
	public function getProxyClassCode()
	{
		$r = rand();
		return $this->_getProxyClassCode((string)$r);
	}
#endif

	/**
	 * gets the HTTP body for the current request.
	 *
	 * @param string $soapmsg The SOAP payload
	 * @return string The HTTP body, which includes the SOAP payload
	 * @access private
	 */
	public function getHTTPBody($soapmsg)
	{
		return $soapmsg;
	}

	/**
	 * gets the HTTP content type for the current request.
	 *
	 * Note: getHTTPBody must be called before this.
	 *
	 * @return string the HTTP content type for the current request.
	 * @access private
	 */
	public function getHTTPContentType()
	{
		return $this->contentType;
	}

	/**
	 * allows you to change the HTTP ContentType of the request.
	 *
	 * @param   string $contentTypeNew
	 * @return  void
	 */
	public function setHTTPContentType($contentTypeNew = "text/xml")
	{
		$this->contentType = $contentTypeNew;
	}

	/**
	 * gets the HTTP content type charset for the current request.
	 * returns false for non-text content types.
	 *
	 * Note: getHTTPBody must be called before this.
	 *
	 * @return string the HTTP content type charset for the current request.
	 * @access private
	 */
	public function getHTTPContentTypeCharset()
	{
		return $this->soap_defencoding;
	}

	/*
	* whether or not parser should decode utf8 element content
	*
	* @return   always returns true
	* @access   public
	*/
	public function decodeUTF8(bool $bool):bool
	{
		$this->decode_utf8 = $bool;
		return true;
	}

	/**
	 * adds a new Cookie into $this->cookies array
	 *
	 * @param    string $name Cookie Name
	 * @param    string $value Cookie Value
	 * @return    bool if cookie-set was successful returns true, else false
	 * @access    public
	 */
	public function setCookie(string $name, string $value):bool
	{
		if (strlen($name) == 0) {
			return false;
		}
		$this->cookies[] = array('name' => $name, 'value' => $value);
		return true;
	}

	/**
	 * gets all Cookies
	 *
	 * @return   mixed[][] with all internal cookies
	 * @access   public
	 */
	public function getCookies()
	{
		return $this->cookies;
	}

	/**
	 * checks all Cookies and delete those which are expired
	 *
	 * @return   bool always return true
	 * @access   private
	 */
	public function checkCookies():bool
	{
		if (sizeof($this->cookies) == 0) {
			return true;
		}
		$this->debug('checkCookie: check ' . sizeof($this->cookies) . ' cookies');
		$curr_cookies = $this->cookies;
		$this->cookies = array();
		foreach ($curr_cookies as $cookie) {
			if (!is_array($cookie)) {
				$this->debug('Remove cookie that is not an array');
				continue;
			}
			if ((isset($cookie['expires'])) && (!empty($cookie['expires']))) {
				if (strtotime($cookie['expires']) > time()) {
					$this->cookies[] = $cookie;
				} else {
					$this->debug('Remove expired cookie ' . $cookie['name']);
				}
			} else {
				$this->cookies[] = $cookie;
			}
		}
		$this->debug('checkCookie: ' . count($this->cookies) . ' cookies left in array');
		return true;
	}

	/**
	 * updates the current cookies with a new set
	 *
	 * @param    mixed[][] $cookies new cookies with which to update current ones
	 * @return    bool always return true
	 * @access    private
	 */
	public function UpdateCookies(array $cookies):bool
	{
		if (count($this->cookies) == 0) {
			// no existing cookies: take whatever is new
			if (count($cookies) > 0) {
				$this->debug('Setting new cookie(s)');
				$this->cookies = $cookies;
			}
			return true;
		}
		if (count($cookies) == 0) {
			// no new cookies: keep what we've got
			return true;
		}
		// merge
		foreach ($cookies as $newCookie) {
			if (!is_array($newCookie)) {
				continue;
			}
			if ((!isset($newCookie['name'])) || (!isset($newCookie['value']))) {
				continue;
			}
			$newName = $newCookie['name'];

			$found = false;
			for ($i = 0, $iMax = count($this->cookies); $i < $iMax; $i++) {
				/** @var mixed[] $cookie */
				$cookie = (array)$this->cookies[$i];
				if (!is_array($cookie)) {
					continue;
				}
				if (!isset($cookie['name'])) {
					continue;
				}
				if ($newName != $cookie['name']) {
					continue;
				}
				$newDomain = isset($newCookie['domain']) ? $newCookie['domain'] : 'NODOMAIN';
				$domain = isset($cookie['domain']) ? $cookie['domain'] : 'NODOMAIN';
				if ($newDomain != $domain) {
					continue;
				}
				$newPath = isset($newCookie['path']) ? $newCookie['path'] : 'NOPATH';
				$path = isset($cookie['path']) ? $cookie['path'] : 'NOPATH';
				if ($newPath != $path) {
					continue;
				}
				$this->cookies[$i] = $newCookie;
				$found = true;
				$this->debug('Update cookie ' . $newName . '=' . $newCookie['value']);
				break;
			}
			if (!$found) {
				$this->debug('Add cookie ' . $newName . '=' . $newCookie['value']);
				$this->cookies[] = $newCookie;
			}
		}
		return true;
	}
}