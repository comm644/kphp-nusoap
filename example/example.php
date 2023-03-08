<?php

//core autoload
include_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../nusoap_extras/nusoap_describer.php';

function verifyResponse(nusoap_client $client)
{
	if  ($client->fault) {
		throw new Exception("Client fault:". $client->faultcode);
	}
	$error = $client->getError();
	if  ($error) {
		throw new Exception("Error:".$error);
	}
}


function main()
{
	/* change to yor url web server */
	//use: Web Service SOAP.Demo at https://www.crcind.com/csp/samples/SOAP.Demo.cls
	$wsdl = 'https://www.crcind.com/csp/samples/SOAP.Demo.cls?wsdl';


	$client = new nusoap_client($wsdl, 'wsdl');
	verifyResponse($client);

#ifndef KPHP
	if ( 0 ) {
		//code generation.
		echo $client->getProxyClassCode();
	}
#endif
	if ( 0 ) {
		//discover SOAP API
		$clientWsdl = new nusoap_wsdl($wsdl);

		$describer = new nusoap_describer();
		//print_r( $describer->describe($clientWsdl) );
		echo $describer->describeAsText($clientWsdl);
	}

	//Usage examples

	$mixed = $client->call('Mission', []);
	verifyResponse($client);
	print_r($mixed);

	$mixed = $client->call('AddInteger', ['Arg1' => 3, 'Arg2' => 5]);
	verifyResponse($client);
	print_r($mixed);

	$mixed = $client->call('FindPerson', ['id' => 1]);
	verifyResponse($client);
	print_r($mixed);
}

main();
