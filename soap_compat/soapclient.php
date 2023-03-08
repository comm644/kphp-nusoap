<?php

if (!extension_loaded('soap')) {
	/**
	 *    For backwards compatiblity, define soapclient unless the PHP SOAP extension is loaded.
	 */
	class soapclient extends nusoap_client
	{
	}
}
