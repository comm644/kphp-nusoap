<?php /** @noinspection PhpTooManyParametersInspection */


/**
 *
 * nusoap_base
 *
 * @author   Dietrich Ayala <dietrich@ganx4.com>
 * @author   Scott Nichol <snichol@users.sourceforge.net>
 * @version  $Id: nusoap.php,v 1.123 2010/04/26 20:15:08 snichol Exp $
 * @access   public
 */
class nusoap_base
{
	public const TYPE_ARRAY_STRUCT = 'arrayStruct';
	public const TYPE_ARRAY_SIMPLE = 'arraySimple';

	/** Identification for HTTP headers. */
	protected string $title = 'NuSOAP';

	/** Version for HTTP headers. */
	protected string $version = '0.9.11';
	/**
	 * CVS revision for HTTP headers.
	 *
	 * @var string
	 * @access private
	 */
	protected string $revision = '$Revision: 1.123 $';


	/** Current error string (manipulated by getError/setError) */
	protected string $error_str = '';

	/** Current debug string (manipulated by debug/appendDebug/clearDebug/getDebug/getDebugAsXMLComment) */
	protected string $debug_str = '';

	/**
	 * toggles automatic encoding of special characters as entities
	 * (should always be true, I think)
	 */
	protected bool $charencoding = true;

	public static int $globalDebugLevel = 9;

	/** the debug level for this instance */
	protected int $debugLevel;

	/** set schema version */
	public string $XMLSchemaVersion = 'http://www.w3.org/2001/XMLSchema';

	/** charset encoding for outgoing messages */
	public string $soap_defencoding = 'ISO-8859-1';
	//var $soap_defencoding = 'UTF-8';

	/**
	 * namespaces in an array of prefix => uri
	 *
	 * this is "seeded" by a set of constants, but it may be altered by code
	 *
	 * @var      string[]
	 * @access   public
	 */
	public $namespaces = array(
		'SOAP-ENV' => 'http://schemas.xmlsoap.org/soap/envelope/',
		'xsd' => 'http://www.w3.org/2001/XMLSchema',
		'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
		'SOAP-ENC' => 'http://schemas.xmlsoap.org/soap/encoding/'
	);

	/**
	 * namespaces used in the current context, e.g. during serialization
	 * @access   private
	 */
	protected array $usedNamespaces = array();

	/**
	 * XML Schema types in an array of uri => (array of xml type => php type)
	 * is this legacy yet?
	 * no, this is used by the nusoap_xmlschema class to verify type => namespace mappings.
	 *
	 * @var      mixed[]
	 * @access   public
	 */
	public array $typemap = array(
		'http://www.w3.org/2001/XMLSchema' => array(
			'string' => 'string', 'boolean' => 'boolean', 'float' => 'double', 'double' => 'double', 'decimal' => 'double',
			'duration' => '', 'dateTime' => 'string', 'time' => 'string', 'date' => 'string', 'gYearMonth' => '',
			'gYear' => '', 'gMonthDay' => '', 'gDay' => '', 'gMonth' => '', 'hexBinary' => 'string', 'base64Binary' => 'string',
			// abstract "any" types
			'anyType' => 'string', 'anySimpleType' => 'string',
			// derived datatypes
			'normalizedString' => 'string', 'token' => 'string', 'language' => '', 'NMTOKEN' => '', 'NMTOKENS' => '', 'Name' => '', 'NCName' => '', 'ID' => '',
			'IDREF' => '', 'IDREFS' => '', 'ENTITY' => '', 'ENTITIES' => '', 'integer' => 'integer', 'nonPositiveInteger' => 'integer',
			'negativeInteger' => 'integer', 'long' => 'integer', 'int' => 'integer', 'short' => 'integer', 'byte' => 'integer', 'nonNegativeInteger' => 'integer',
			'unsignedLong' => '', 'unsignedInt' => '', 'unsignedShort' => '', 'unsignedByte' => '', 'positiveInteger' => ''),
		'http://www.w3.org/2000/10/XMLSchema' => array(
			'i4' => '', 'int' => 'integer', 'boolean' => 'boolean', 'string' => 'string', 'double' => 'double',
			'float' => 'double', 'dateTime' => 'string',
			'timeInstant' => 'string', 'base64Binary' => 'string', 'base64' => 'string', 'ur-type' => 'array'),
		'http://www.w3.org/1999/XMLSchema' => array(
			'i4' => '', 'int' => 'integer', 'boolean' => 'boolean', 'string' => 'string', 'double' => 'double',
			'float' => 'double', 'dateTime' => 'string',
			'timeInstant' => 'string', 'base64Binary' => 'string', 'base64' => 'string', 'ur-type' => 'array'),
		'http://soapinterop.org/xsd' => array('SOAPStruct' => 'struct'),
		'http://schemas.xmlsoap.org/soap/encoding/' => array('base64' => 'string', 'array' => 'array', 'Array' => 'array'),
		'http://xml.apache.org/xml-soap' => array('Map')
	);

	/**
	 * XML entities to convert
	 *
	 * @var      string[]
	 * @access   public
	 * @deprecated
	 * @see    expandEntities
	 */
	public array $xmlEntities = array(
		'quot' => '"',
		'amp' => '&',
		'lt' => '<',
		'gt' => '>',
		'apos' => "'");

	/** HTTP Content-type to be used for SOAP calls and responses */
	public string $contentType = "text/xml";


	/** constructor */
	public function __construct()
	{
		$this->debugLevel = self::$globalDebugLevel;
	}

	/**
	 * gets the global debug level, which applies to future instances
	 *
	 * @return    int    Debug level 0-9, where 0 turns off
	 * @access    public
	 */
	public function getGlobalDebugLevel(): int
	{
		return self::$globalDebugLevel;
	}

	/**
	 * sets the global debug level, which applies to future instances
	 *
	 * @param    int $level Debug level 0-9, where 0 turns off
	 * @access    public
	 */
	public function setGlobalDebugLevel($level)
	{
		self::$globalDebugLevel = $level;
	}

	/**
	 * gets the debug level for this instance
	 *
	 * @return    int    Debug level 0-9, where 0 turns off
	 * @access    public
	 */
	public function getDebugLevel()
	{
		return $this->debugLevel;
	}

	/**
	 * sets the debug level for this instance
	 *
	 * @param    int $level Debug level 0-9, where 0 turns off
	 * @access    public
	 */
	public function setDebugLevel($level)
	{
		$this->debugLevel = $level;
	}

	/**
	 * adds debug data to the instance debug string with formatting
	 *
	 * @param    string $message debug data
	 * @access   private
	 */
	public function debug($message)
	{
		if ($this->debugLevel > 0) {
			$this->appendDebug($this->getmicrotime() . ' ' . get_class($this) . ": $message\n");
		}
	}

	/**
	 * adds debug data to the instance debug string without formatting
	 *
	 * @param    string $string debug data
	 * @access   public
	 */
	public function appendDebug($string)
	{
		if ($this->debugLevel > 0) {
			// it would be nice to use a memory stream here to use
			// memory more efficiently
			$this->debug_str .= $string;
		}
	}

	/**
	 * clears the current debug data for this instance
	 *
	 * @access   public
	 */
	public function clearDebug()
	{
		// it would be nice to use a memory stream here to use
		// memory more efficiently
		$this->debug_str = '';
	}

	/**
	 * gets the current debug data for this instance
	 *
	 * @return string  debug data
	 * @access   public
	 */
	public function getDebug(): string
	{
		// it would be nice to use a memory stream here to use
		// memory more efficiently
		return $this->debug_str;
	}

	/**
	 * gets the current debug data for this instance as an XML comment
	 * this may change the contents of the debug data
	 *
	 * @return string  debug data as an XML comment
	 * @access   public
	 */
	public function getDebugAsXMLComment(): string
	{
		// it would be nice to use a memory stream here to use
		// memory more efficiently
		while (strpos($this->debug_str, '--')) {
			$this->debug_str = str_replace('--', '- -', $this->debug_str);
		}
		return "<!--\n" . $this->debug_str . "\n-->";
	}

	/**
	 * expands entities, e.g. changes '<' to '&lt;'.
	 *
	 * @param    string $val The string in which to expand entities.
	 * @access    private
	 */
	protected function expandEntities(string $val): string
	{
		if ($this->charencoding) {
			$val = str_replace(
				['&', "'", '"', '<', '>'],
				['&amp;', '&apos;', '&quot;', '&lt;', '&gt;'], $val);
		}
		return $val;
	}

	/**
	 * returns error string if present
	 *
	 * @return   string error string or false
	 * @access   public
	 */
	public function getError(): string
	{
		if ($this->error_str != '') {
			return $this->error_str;
		}
		return '';
	}

	/**
	 * sets error string
	 *
	 * @param   string $message error string
	 * @access   private
	 */
	public function setError(string $message): void
	{
		$this->error_str = $message;
		$this->debug($message);
	}

	/**
	 * detect if array is a simple array or a struct (associative array)
	 *
	 * @param    mixed $val The PHP array
	 * @return    string    (arraySimple|arrayStruct)
	 * @access    private
	 */
	public function isArraySimpleOrStruct($val): string
	{
		$keyList = array_keys($val);
		foreach ($keyList as $keyListValue) {
			if (!is_int($keyListValue)) {
				return self::TYPE_ARRAY_STRUCT;
			}
		}
		return self::TYPE_ARRAY_SIMPLE;
	}

	/**
	 * serializes PHP values in accordance w/ section 5. Type information is
	 * not serialized if $use == 'literal'.
	 *
	 * @param    mixed $val The value to serialize
	 * @param    string $name The name (local part) of the XML element
	 * @param    string $type The XML schema type (local part) for the element
	 * @param    string $name_ns The namespace for the name of the XML element
	 * @param    string $type_ns The namespace for the type of the element
	 * @param    string[] $attributes The attributes to serialize as name=>value pairs
	 * @param    string $use The WSDL "use" (encoded|literal)
	 * @param    bool $soapval Whether this is called from soapval.
	 * @return    string    The serialized element, possibly with child elements
	 * @access    public
	 */
	public function serialize_val($val, $name = '', $type = '',
	                              $name_ns = '',
	                              $type_ns = '',
	                              $attributes = [],
	                              $use = 'encoded',
	                              $soapval = false)
	{
		$tt_ns = '';
		$array_types = [];

		$this->debug("in serialize_val: name=$name, type=$type, name_ns=$name_ns, type_ns=$type_ns, use=$use, soapval=$soapval");
		$this->appendDebug('value=' . $this->varDump($val));
		$this->appendDebug('attributes=' . $this->varDump($attributes));

		if (is_object($val) && (!$soapval)) {
#ifndef KPHP
			if ( $val instanceof nusoap_soapval) {
				/** @var  nusoap_soapval $objectSoapVal */
				$objectSoapVal = $val;
				$this->debug("serialize_val: serialize soapval");
				$xml = $objectSoapVal->serialize($use);
				$this->appendDebug($objectSoapVal->getDebug());
				$objectSoapVal->clearDebug();
				$this->debug("serialize_val of soapval returning $xml");
				return $xml;
			}
#endif
			/** @noinspection PhpUnreachableStatementInspection */
			throw new Exception("not supported in KPHP");
		}
		// force valid name if necessary
		if (is_numeric($name)) {
			$name = '__numeric_' . $name;
		} else if (!$name) {
			$name = 'noname';
		}
		// if name has ns, add ns prefix to name
		$xmlns = '';
		if ($name_ns) {
			$prefix = 'nu' . rand(1000, 9999);
			$name = $prefix . ':' . $name;
			$xmlns .= " xmlns:$prefix=\"$name_ns\"";
		}
		// if type is prefixed, create type prefix
		$type_prefix = '';
		if ((!!$type_ns) && ($type_ns === $this->namespaces['xsd'])) {
			// need to fix this. shouldn't default to xsd if no ns specified
			// w/o checking against typemap
			$type_prefix = 'xsd';
		} else if ($type_ns) {
			$type_prefix = 'ns' . rand(1000, 9999);
			$xmlns .= " xmlns:$type_prefix=\"$type_ns\"";
		}
		// serialize attributes if present
		$atts = '';
		foreach ($attributes as $k => $v) {
			$atts .= " $k=\"" . $this->expandEntities($v) . '"';
		}
		// serialize null value
		if (is_null($val)) {
			$this->debug("serialize_val: serialize null");
			if ($use === 'literal') {
				// TODO: depends on minOccurs
				$xml = "<$name$xmlns$atts/>";
				$this->debug("serialize_val returning $xml");
				return $xml;
			}
			if (!!$type && !!$type_prefix) {
				$type_str = " xsi:type=\"$type_prefix:$type\"";
			} else {
				$type_str = '';
			}
			$xml = "<$name$xmlns$type_str$atts xsi:nil=\"true\"/>";
			$this->debug("serialize_val returning $xml");
			return $xml;
		}

		// serialize if an xsd built-in primitive type
		if ((!!$type) && isset($this->typemap[$this->XMLSchemaVersion][$type])) {
			$this->debug("serialize_val: serialize xsd built-in primitive type");
			if (is_bool($val)) {
				if ($type === 'boolean') {
					$val = $val ? 'true' : 'false';
				} else if (!$val) {
					$val = 0;
				}
			} else if (is_string($val)) {
				$val = $this->expandEntities($val);
			}
			if ($use === 'literal') {
				$xml = "<$name$xmlns$atts>$val</$name>";
				$this->debug("serialize_val returning $xml");
				return $xml;
			}
			$xml = "<$name$xmlns xsi:type=\"xsd:$type\"$atts>$val</$name>";
			$this->debug("serialize_val returning $xml");
			return $xml;
		}
		// detect type and serialize
		$xml = '';
		if ((true === is_bool($val)) || ($type === 'boolean')) {
			$this->debug("serialize_val: serialize boolean");
			if ($type === 'boolean') {
				$val = $val ? 'true' : 'false';
			} else if (!$val) {
				$val = 0;
			}
			if ($use === 'literal') {
				$xml .= "<$name$xmlns$atts>$val</$name>";
			} else {
				$xml .= "<$name$xmlns xsi:type=\"xsd:boolean\"$atts>$val</$name>";
			}
		} else if ((true == is_int($val)) || ($type === 'int')) {
			$this->debug("serialize_val: serialize int");
			if ($use === 'literal') {
				$xml .= "<$name$xmlns$atts>$val</$name>";
			} else {
				$xml .= "<$name$xmlns xsi:type=\"xsd:int\"$atts>$val</$name>";
			}
		} else if ((true == is_float($val)) || ($type === 'float')) {
			$this->debug("serialize_val: serialize float");
			if ($use === 'literal') {
				$xml .= "<$name$xmlns$atts>$val</$name>";
			} else {
				$xml .= "<$name$xmlns xsi:type=\"xsd:float\"$atts>$val</$name>";
			}
		} else if ((true == is_string($val)) || ($type === 'string')) {
			$this->debug("serialize_val: serialize string");
			$val = $this->expandEntities((string)$val);
			if ($use == 'literal') {
				$xml .= "<$name$xmlns$atts>$val</$name>";
			} else {
				$xml .= "<$name$xmlns xsi:type=\"xsd:string\"$atts>$val</$name>";
			}
		} else if (is_object($val)) {
#ifndef KPHP
			$this->debug("serialize_val: serialize object");
			if ($val instanceof nusoap_soapval) {
				$this->debug("serialize_val: serialize soapval object");
				$pXml = $val->serialize($use);
				$this->appendDebug($val->getDebug());
				$val->clearDebug();
			} else {
				if (!$name) {
					$name = get_class($val);
					$this->debug("In serialize_val, used class name $name as element name");
				} else {
					$this->debug("In serialize_val, do not override name $name for element name for class " . get_class($val));
				}
				foreach (get_object_vars($val) as $k => $v) {
					$pXml = $pXml ? ($pXml . $this->serialize_val($v, (string)$k, '', '', '', [], $use)) : $this->serialize_val($v, (string)$k, '', '', '', [], $use);
				}
			}
			if ($type && $type_prefix) {
				$type_str = " xsi:type=\"$type_prefix:$type\"";
			} else {
				$type_str = '';
			}
			if ($use === 'literal') {
				$xml .= "<$name$xmlns$atts>$pXml</$name>";
			} else {
				$xml .= "<$name$xmlns$type_str$atts>$pXml</$name>";
			}
#endif
			/** @noinspection PhpUnreachableStatementInspection */
			throw new Exception("Not supported in KPHP");
		} else if (is_array($val) || $type) {
			// detect if struct or array
			$valueType = $this->isArraySimpleOrStruct($val);
			if (($valueType === self::TYPE_ARRAY_SIMPLE) || preg_match('/^ArrayOf/', $type)) {
				$this->debug("serialize_val: serialize array");
				$i = 0;
				$tt = null;
				if (is_array($val) && (count($val) > 0)) {
					foreach ($val as $v) {
						if (is_object($v)) {
							$kphp = true;
#ifndef KPHP
							if (($v instanceof nusoap_soapval)) {
								/** @var nusoap_soapval $objectSoapVal */
								$objectSoapVal = $v;
								$tt_ns = $objectSoapVal->type_ns;
								$tt = $objectSoapVal->type;
							} else {
								throw new Exception("not supported value");
							}
							$kphp = false;
#endif
							if ($kphp) {
								/** @noinspection PhpUnreachableStatementInspection */
								throw new Exception("not supported in KPHP");
							}
						} else if (is_array($v)) {
							$tt = $this->isArraySimpleOrStruct($v);
						} else {
							$tt = gettype($v);
						}
						$array_types[$tt] = 1;
						// TODO: for literal, the name should be $name
						$xml .= $this->serialize_val((string)$v, 'item', '', '', '', [], $use);
						++$i;
					}
					if (count($array_types) > 1) {
						$array_typename = 'xsd:anyType';
					} else if (!!$tt && isset($this->typemap[$this->XMLSchemaVersion][$tt])) {
						if ($tt === 'integer') {
							$tt = 'int';
						}
						$array_typename = 'xsd:' . $tt;
					} else if ($tt && ($tt === self::TYPE_ARRAY_SIMPLE)) {
						$array_typename = 'SOAP-ENC:Array';
					} else if ($tt && ($tt === self::TYPE_ARRAY_STRUCT)) {
						$array_typename = 'unnamed_struct_use_soapval';
					} else {
						// if type is prefixed, create type prefix
						if (($tt_ns != '') && ($tt_ns === $this->namespaces['xsd'])) {
							$array_typename = 'xsd:' . $tt;
						} else if ($tt_ns) {
							$tt_prefix = 'ns' . rand(1000, 9999);
							$array_typename = "$tt_prefix:$tt";
							$xmlns .= " xmlns:$tt_prefix=\"$tt_ns\"";
						} else {
							$array_typename = $tt;
						}
					}
					$array_type = $i;
					if ($use === 'literal') {
						$type_str = '';
					} else if (!!$type && !!$type_prefix) {
						$type_str = " xsi:type=\"$type_prefix:$type\"";
					} else {
						$type_str = " xsi:type=\"SOAP-ENC:Array\" SOAP-ENC:arrayType=\"" . $array_typename . "[$array_type]\"";
					}
					// empty array
				} else {
					if ($use === 'literal') {
						$type_str = '';
					} else if (!!$type && !!$type_prefix) {
						$type_str = " xsi:type=\"$type_prefix:$type\"";
					} else {
						$type_str = " xsi:type=\"SOAP-ENC:Array\" SOAP-ENC:arrayType=\"xsd:anyType[0]\"";
					}
				}
				// TODO: for array in literal, there is no wrapper here
				$xml = "<$name$xmlns$type_str$atts>" . $xml . "</$name>";
			} else {
				// got a struct
				$this->debug("serialize_val: serialize struct");
				if (!!$type && !!$type_prefix) {
					$type_str = " xsi:type=\"$type_prefix:$type\"";
				} else {
					$type_str = '';
				}
				if ($use === 'literal') {
					$xml .= "<$name$xmlns$atts>";
				} else {
					$xml .= "<$name$xmlns$type_str$atts>";
				}
				foreach ($val as $k => $v) {
					// Apache Map
					if (($type === 'Map') && ($type_ns === 'http://xml.apache.org/xml-soap')) {
						$xml .= '<item>';
						$xml .= $this->serialize_val((string)$k, 'key', '', '', '', [], $use);
						$xml .= $this->serialize_val((string)$v, 'value', '', '', '', [], $use);
						$xml .= '</item>';
					} else {
						$xml .= $this->serialize_val((string)$v, (string)$k, '', '', '', [], $use);
					}
				}
				$xml .= "</$name>";
			}
		} else {
			$this->debug("serialize_val: serialize unknown");
			$xml .= 'not detected, got ' . gettype($val) . ' for ' . $val;
		}
		$this->debug("serialize_val returning $xml");
		return $xml;
	}

	/**
	 * serializes a message
	 *
	 * @param string $body the XML of the SOAP body
	 * @param mixed $headers optional string of XML with SOAP header content, or array of soapval objects for SOAP headers, or associative array
	 * @param string[] $namespaces optional the namespaces used in generating the body and headers
	 * @param string $style optional (rpc|document)
	 * @param string $use optional (encoded|literal)
	 * @param string $encodingStyle optional (usually 'http://schemas.xmlsoap.org/soap/encoding/' for encoded)
	 * @return string the message
	 * @access public
	 */
	public function serializeEnvelope(string $body, $headers = null, array $namespaces = array(),
	                                  string $style = 'rpc',
	                                  string $use = 'encoded',
	                                  string $encodingStyle = 'http://schemas.xmlsoap.org/soap/encoding/'): string
	{
		// TODO: add an option to automatically run utf8_encode on $body and $headers
		// if $this->soap_defencoding is UTF-8.  Not doing this automatically allows
		// one to send arbitrary UTF-8 characters, not just characters that map to ISO-8859-1

		$this->debug("In serializeEnvelope length=" . strlen($body) . " body (max 1000 characters)=" . substr($body, 0, 1000) . " style=$style use=$use encodingStyle=$encodingStyle");
		$this->debug("headers:");
		$this->appendDebug($this->varDump($headers));
		$this->debug("namespaces:");
		$this->appendDebug($this->varDump($namespaces));

		// serialize namespaces
		$ns_string = '';
		foreach (array_merge($this->namespaces, $namespaces) as $k => $v) {
			$ns_string .= " xmlns:$k=\"$v\"";
		}
		if ($encodingStyle) {
			$ns_string = " SOAP-ENV:encodingStyle=\"$encodingStyle\"$ns_string";
		}

		// serialize headers
		if ($headers) {
			if (is_array($headers)) {
				$xml = '';
				foreach ($headers as $k => $v) {
					if (is_object($v)) {
						$kphp = false;
#ifndef KPHP
						if (($v instanceof nusoap_soapval)) {
							$xml .= $this->serialize_val((string)$v, '', '', '', '', [], $use);
						} else {
							throw new Exception("not supported in value");
						}
						$kphp =true;
#endif
						if ($kphp)
						{
							/** @noinspection PhpUnreachableStatementInspection */
							throw new Exception("not supported in KPHP");
						}
					} else {
						$xml .= $this->serialize_val($v, (string)$k, '', '', '', [], $use);
					}
				}
				$headers = $xml;
				$this->debug("In serializeEnvelope, serialized array of headers to $headers");
			}
			$headers = "<SOAP-ENV:Header>" . $headers . "</SOAP-ENV:Header>";
		}
		// serialize envelope
		return
			'<?xml version="1.0" encoding="' . $this->soap_defencoding . '"?' . ">" .
			'<SOAP-ENV:Envelope' . $ns_string . ">" .
			$headers .
			"<SOAP-ENV:Body>" .
			$body .
			"</SOAP-ENV:Body>" .
			"</SOAP-ENV:Envelope>";
	}

	/**
	 * formats a string to be inserted into an HTML stream
	 *
	 * @param string $str The string to format
	 * @return string The formatted string
	 * @access public
	 * @deprecated
	 */
	public function formatDump($str)
	{
		$str = htmlspecialchars($str);
		return nl2br($str);
	}

	/**
	 * contracts (changes namespace to prefix) a qualified name
	 *
	 * @param    string $qname qname
	 * @return   string contracted qname
	 * @access   private
	 */
	public function contractQName(string $qname): string
	{
		// get element namespace
		//$this->xdebug("Contract $qname");
		if (!strrpos($qname, ':')) {
			return $qname;
		}

		// get unqualified name
		$name = substr($qname, strrpos($qname, ':') + 1);
		// get ns
		$ns = (string)substr($qname, 0, strrpos($qname, ':'));
		$p = $this->getPrefixFromNamespace($ns);
		if ($p) {
			return $p . ':' . $name;
		}
		return $qname;
	}

	/**
	 * expands (changes prefix to namespace) a qualified name
	 *
	 * @param    string $qname qname
	 * @return   string expanded qname
	 * @access   private
	 */
	public function expandQname(string $qname): string
	{
		// get element prefix
		if (!strpos($qname, ':') || preg_match('/^http:\/\//', $qname)) {
			return $qname;
		}

		// get unqualified name
		$name = substr(strstr($qname, ':'), 1);
		// get ns prefix
		$prefix = substr($qname, 0, strpos($qname, ':'));
		if (!isset($this->namespaces[$prefix])) {
			return $qname;
		}

		return $this->namespaces[$prefix] . ':' . $name;
	}

	/**
	 * returns the local part of a prefixed string
	 * returns the original string, if not prefixed
	 *
	 * @param string $str The prefixed string
	 * @return string The local part
	 * @access public
	 */
	public function getLocalPart(string $str): string
	{
		$sstr = strrchr($str, ':');
		if (!$sstr) {
			return $str;
		}

		// get unqualified name
		return (string)substr($sstr, 1);
	}

	/**
	 * returns the prefix part of a prefixed string
	 * returns false, if not prefixed
	 *
	 * @param string $str The prefixed string
	 * @return string The prefix or false if there is no prefix
	 * @access public
	 */
	public function getPrefix($str): string
	{
		$pos = strrpos($str, ':');
		if (!$pos) {
			return '';
		}

		// get prefix
		return (string)substr($str, 0, $pos);
	}

	/**
	 * pass it a prefix, it returns a namespace
	 *
	 * @param string $prefix The prefix
	 * @return string The namespace, false if no namespace has the specified prefix
	 */
	public function getNamespaceFromPrefix($prefix): string
	{
		if (isset($this->namespaces[$prefix])) {
			return (string)$this->namespaces[$prefix];
		}
		//$this->setError("No namespace registered for prefix '$prefix'");
		return '';
	}

	/**
	 * returns the prefix for a given namespace (or prefix)
	 * or false if no prefixes registered for the given namespace
	 *
	 * @param string $ns The namespace
	 * @return string  The prefix, false if the namespace has no prefixes
	 * @access public
	 */
	public function getPrefixFromNamespace(string $ns): string
	{
		foreach ($this->namespaces as $p => $n) {
			if (($ns == $n) || ($ns == $p)) {
				$this->usedNamespaces[$p] = $n;
				return (string)$p;
			}
		}
		return '';
	}

	/**
	 * returns the time in ODBC canonical form with microseconds
	 *
	 * @return string The time in ODBC canonical form with microseconds
	 * @access public
	 */
	public function getmicrotime(): string
	{
		$sec = time();
		$usec = 0;
		return strftime('%Y-%m-%d %H:%M:%S', $sec) . '.' . sprintf('%06d', $usec);
	}

	/**
	 * Returns a string with the output of var_dump
	 *
	 * @param mixed|mixed[] $data The variable to var_dump
	 * @return string The output of var_dump
	 * @access public
	 */
	public function varDump($data): string
	{
		ob_start();
		var_dump($data);
		$ret_val = ob_get_contents();
		ob_end_clean();
		return $ret_val;
	}

	/**
	 * represents the object as a string
	 *
	 * @return    string
	 * @access   public
	 */
	public function __toString(): string
	{
		return $this->varDump(get_object_vars($this));
	}
	/**
	 * @param mixed $array
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public static function getProp($array, string $key, $default='' )
	{
		if  (isset($array[$key])){
			return $array[$key];
		}
		return $default;
	}
}