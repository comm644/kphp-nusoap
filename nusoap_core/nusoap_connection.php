<?php

class nusoap_connection
{
	// for getting wsdl
	public string $proxyhost = ''; //use an empty string to remove proxy
	public string $proxyport = '';
	public string $proxyusername = '';
	public string $proxypassword = '';
	public int $timeout = 0;                // HTTP connection timeout
	public int $response_timeout = 30;        // HTTP response timeout

	/**
	 * constructor
	 *
	 * @param    string $proxyhost optional
	 * @param    string $proxyport optional
	 * @param    string $proxyusername optional
	 * @param    string $proxypassword optional
	 * @param    int $timeout set the connection timeout
	 * @param    int $response_timeout set the response timeout
	 */
	public function __construct(string $proxyhost = '',
	                            string $proxyport = '',
	                            string $proxyusername = '',
	                            string $proxypassword = '',
	                            int    $timeout = 0,
	                            int    $response_timeout = 30)
	{
		$this->proxyhost = $proxyhost;
		$this->proxyport = $proxyport;
		$this->proxyusername = $proxyusername;
		$this->proxypassword = $proxypassword;
		$this->timeout = $timeout;
		$this->response_timeout = $response_timeout;
	}
}