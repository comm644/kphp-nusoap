<?php

/*
$Id: nusoap.php,v 1.123 2010/04/26 20:15:08 snichol Exp $

NuSOAP - Web Services Toolkit for PHP

Copyright (c) 2002 NuSphere Corporation

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

The NuSOAP project home is:
http://sourceforge.net/projects/nusoap/

The primary support for NuSOAP is the Help forum on the project home page.

If you have any questions or comments, please email:

Dietrich Ayala
dietrich@ganx4.com
http://dietrich.ganx4.com/nusoap

NuSphere Corporation
http://www.nusphere.com

*/

/*
 *	Some of the standards implemented in whole or part by NuSOAP:
 *
 *	SOAP 1.1 (http://www.w3.org/TR/2000/NOTE-SOAP-20000508/)
 *	WSDL 1.1 (http://www.w3.org/TR/2001/NOTE-wsdl-20010315)
 *	SOAP Messages With Attachments (http://www.w3.org/TR/SOAP-attachments)
 *	XML 1.0 (http://www.w3.org/TR/2006/REC-xml-20060816/)
 *	Namespaces in XML 1.0 (http://www.w3.org/TR/2006/REC-xml-names-20060816/)
 *	XML Schema 1.0 (http://www.w3.org/TR/xmlschema-0/)
 *	RFC 2045 Multipurpose Internet Mail Extensions (MIME) Part One: Format of Internet Message Bodies
 *	RFC 2068 Hypertext Transfer Protocol -- HTTP/1.1
 *	RFC 2617 HTTP Authentication: Basic and Digest Access Authentication
 */

require_once __DIR__ . '/nusoap_core/nusoap_connection.php';

// XML Schema Datatype Helper Functions
require_once __DIR__ . '/nusoap_core/nusoap_base.php';

// necessary classes
require_once __DIR__ . '/nusoap_core/nusoap_client.php';
require_once __DIR__ . '/nusoap_core/nusoap_soapval.php';
require_once __DIR__ . '/nusoap_core/nusoap_parser.php';
require_once __DIR__ . '/nusoap_core/nusoap_fault.php';

// transport classes
require_once __DIR__ . '/nusoap_core/nusoap_transport_http.php';

// optional add-on classes
require_once __DIR__ . '/nusoap_core/nusoap_xmlschema.php';
require_once __DIR__ . '/nusoap_core/nusoap_wsdl.php';

#ifndef KPHP
require_once __DIR__ . '/nusoap_core/nusoap_wsdlcache.php';
// server class
require_once __DIR__ . '/nusoap_core/nusoap_server.php';
#endif

/**
 * Backward compatibility
 */
require_once __DIR__ . '/soap_compat/XMLSchema.php';
require_once __DIR__ . '/soap_compat/soap_fault.php';
require_once __DIR__ . '/soap_compat/soap_parser.php';
#ifndef KPHP
require_once __DIR__ . '/soap_compat/soap_server.php';
require_once __DIR__ . '/soap_compat/wsdlcache.php';
require_once __DIR__ . '/soap_compat/soapclient.php';
#endif
