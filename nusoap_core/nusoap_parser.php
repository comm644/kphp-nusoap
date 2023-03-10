<?php


/**
 *
 * nusoap_parser class parses SOAP XML messages into native PHP values
 *
 * @author   Dietrich Ayala <dietrich@ganx4.com>
 * @author   Scott Nichol <snichol@users.sourceforge.net>
 * @version  $Id: nusoap.php,v 1.123 2010/04/26 20:15:08 snichol Exp $
 * @access   public
 */
class nusoap_parser extends nusoap_base
{

	public $xml = '';
	public $xml_encoding = '';
	public int $root_struct = 0;
	public string $root_struct_name = '';
	public $root_struct_namespace = '';
	public int $root_header = 0;
	public $document = '';            // incoming SOAP body (text)
	// determines where in the message we are (envelope,header,body,method)
	public $status = '';
	public $position = 0;
	public $depth = 0;
	public $default_namespace = '';
	/** @var mixed */
	public $message = array();
	public $parent = 0;
	public $fault = false;
	public $fault_code = '';
	public $fault_str = '';
	public $fault_detail = '';
	public $depth_array = array();
	public $debug_flag = true;
	public $soapresponse = null;    // parsed SOAP Body
	/** @var mixed|null  */
	public $soapheader = null;        // parsed SOAP Header
	public $responseHeaders = '';    // incoming SOAP headers (text)
	public $body_position = 0;
	// for multiref parsing:
	/** @var int[]  array of id => pos */
	public array$ids = array();
	// array of id => hrefs => pos
	public array $multirefs = array();
	// toggle for auto-decoding element content
	public $decode_utf8 = true;
	/** @var XMLParser  */
	private $parser;
	private string $methodNamespace;

	/**
	 * constructor that actually does the parsing
	 *
	 * @param    string $xml SOAP message
	 * @param    string $encoding character encoding scheme of message
	 * @param    bool $decode_utf8 whether to decode UTF-8 to ISO-8859-1
	 * @access   public
	 */
	public function __construct(string $xml, string $encoding = 'UTF-8', bool $decode_utf8 = true)
	{
		parent::__construct();
		$this->xml = $xml;
		$this->xml_encoding = $encoding;
		$this->decode_utf8 = $decode_utf8;

		// Check whether content has been read.
		if (!empty($xml)) {
			// Check XML encoding
			$pos_xml = strpos($xml, '<?xml');
			if ($pos_xml !== false) {
				$xml_decl = substr($xml, $pos_xml, (strpos($xml, '?>', $pos_xml + 2) - $pos_xml) + 1);
				if (preg_match("/encoding=[\"']([^\"']*)[\"']/", $xml_decl, $res)) {
					$xml_encoding = $res[1];
					if (strtoupper($xml_encoding) != $encoding) {
						$err = "Charset from HTTP Content-Type '" . $encoding . "' does not match encoding from XML declaration '" . $xml_encoding . "'";
						$this->debug($err);
						if (($encoding !== 'ISO-8859-1') || (strtoupper($xml_encoding) !== 'UTF-8')) {
							$this->setError($err);
							return;
						}
						// when HTTP says ISO-8859-1 (the default) and XML says UTF-8 (the typical), assume the other endpoint is just sloppy and proceed
					} else {
						$this->debug('Charset from HTTP Content-Type matches encoding from XML declaration');
					}
				} else {
					$this->debug('No encoding specified in XML declaration');
				}
			} else {
				$this->debug('No XML declaration');
			}
			$this->debug('Entering nusoap_parser(), length=' . strlen($xml) . ', encoding=' . $encoding);
			// Create an XML parser - why not xml_parser_create_ns?
			$this->parser = xml_parser_create($this->xml_encoding);
			// Set the options for parsing the XML data.
			//xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
			xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, 0);
			xml_parser_set_option($this->parser, XML_OPTION_TARGET_ENCODING, $this->xml_encoding);
			// Set the object for the parser.
			xml_set_object($this->parser, $this);
			// Set the element handlers for the parser.
			xml_set_element_handler($this->parser,
				function ($parser, $name, $attrs) {
					$this->start_element($parser, $name, $attrs);
				},
				function ($parser, $name) {
					$this->end_element($parser, $name);
				});
			xml_set_character_data_handler($this->parser, function ($parser, $data) {
				$this->character_data($parser, $data);
			});
			$parseErrors = array();
			$chunkSize = 4096;
			for ($pointer = 0; ($pointer < strlen($xml)) && empty($parseErrors); $pointer += $chunkSize) {
				$xmlString = substr($xml, $pointer, $chunkSize);
				if (!xml_parse($this->parser, (string)$xmlString, false)) {
					$parseErrors['lineNumber'] = xml_get_current_line_number($this->parser);
					$parseErrors['errorString'] = xml_error_string(xml_get_error_code($this->parser));
				};
			}
			//Tell the script that is the end of the parsing (by setting is_final to TRUE)
			xml_parse($this->parser, '', true);

			if (!empty($parseErrors)) {
				// Display an error message.
				$err = sprintf('XML error parsing SOAP payload on line %d: %s',
					$parseErrors['lineNumber'],
					$parseErrors['errorString']);
				$this->debug($err);
				$this->setError($err);
			} else {
				$this->debug('in nusoap_parser ctor, message:');
				$this->appendDebug($this->varDump($this->message));
				$this->debug('parsed successfully, found root struct: ' . $this->root_struct . ' of name ' . $this->root_struct_name);
				// get final value
				$this->soapresponse = $this->message[$this->root_struct]['result'];
				// get header value
				if (($this->root_header != '') && isset($this->message[$this->root_header]['result'])) {
					$this->soapheader = $this->message[$this->root_header]['result'];
				}
				// resolve hrefs/ids
				if (sizeof($this->multirefs) > 0) {
					foreach ($this->multirefs as $id => $hrefs) {
						$this->debug('resolving multirefs for id: ' . $id);
						$idVal = $this->buildVal($this->ids[$id]);
						if (is_array($idVal) && isset($idVal['!id'])) {
							unset($idVal['!id']);
						}
						foreach ($hrefs as $refPos => $ref) {
							$this->debug('resolving href at pos ' . $refPos);
							$this->multirefs[$id][$refPos] = $idVal;
						}
					}
				}
			}
			xml_parser_free($this->parser);
			$this->parser = null;
		} else {
			$this->debug('xml was empty, didn\'t parse!');
			$this->setError('xml was empty, didn\'t parse!');
		}
	}

	/**
	 * start-element handler
	 *
	 * @param    mixed $parser XML parser object
	 * @param    string $name element name
	 * @param    string[] $attrs associative array of attributes
	 * @access   private
	 */
	public function start_element($parser, string $name, array $attrs)
	{
		// position in a total number of elements, starting from 0
		// update class level pos
		$pos = $this->position++;
		// and set mine
		$this->message[$pos] = array('pos' => $pos, 'children' => '', 'cdata' => '');
		// depth = how many levels removed from root?
		// set mine as current global depth and increment global depth value
		$this->message[$pos]['depth'] = $this->depth++;

		// else add self as child to whoever the current parent is
		if ($pos != 0) {
			$this->message[$this->parent]['children'] .= '|' . $pos;
		}
		// set my parent
		$this->message[$pos]['parent'] = $this->parent;
		// set self as current parent
		$this->parent = $pos;
		// set self as current value for this depth
		$this->depth_array[$this->depth] = $pos;
		// get element prefix
		if (strpos($name, ':')) {
			// get ns prefix
			$prefix = substr($name, 0, strpos($name, ':'));
			// get unqualified name
			$name = substr(strstr($name, ':'), 1);
		}
		// set status
		if (($name === 'Envelope') && ($this->status === '')) {
			$this->status = 'envelope';
		} else if (($name === 'Header') && ($this->status === 'envelope')) {
			$this->root_header = $pos;
			$this->status = 'header';
		} else if (($name === 'Body') && ($this->status === 'envelope')) {
			$this->status = 'body';
			$this->body_position = $pos;
			// set method
		} else if (($this->status === 'body') && ($pos == ($this->body_position + 1))) {
			$this->status = 'method';
			$this->root_struct_name = (string)$name;
			$this->root_struct = $pos;
			$this->message[$pos]['type'] = 'struct';
			$this->debug("found root struct $this->root_struct_name, pos $this->root_struct");
		}
		// set my status
		$this->message[$pos]['status'] = $this->status;
		// set name
		$this->message[$pos]['name'] = htmlspecialchars($name);
		// set attrs
		$this->message[$pos]['attrs'] = $attrs;

		// loop through atts, logging ns and type declarations
		$attstr = '';
		foreach ($attrs as $key => $value) {
			$key_prefix = $this->getPrefix((string)$key);
			$key_localpart = $this->getLocalPart((string)$key);
			// if ns declarations, add to class level array of valid namespaces
			if ($key_prefix === 'xmlns') {
				if (preg_match('/^http:\/\/www.w3.org\/[0-9]{4}\/XMLSchema$/', $value)) {
					$this->XMLSchemaVersion = $value;
					$this->namespaces['xsd'] = $this->XMLSchemaVersion;
					$this->namespaces['xsi'] = $this->XMLSchemaVersion . '-instance';
				}
				$this->namespaces[$key_localpart] = $value;
				// set method namespace
				if ($name == $this->root_struct_name) {
					$this->methodNamespace = $value;
				}
				// if it's a type declaration, set type
			} else if ($key_localpart === 'type') {
				if (isset($this->message[$pos]['type']) && ($this->message[$pos]['type'] === 'array')) {
					// do nothing: already processed arrayType
				} else {
					$value_prefix = $this->getPrefix($value);
					$value_localpart = $this->getLocalPart($value);
					$this->message[$pos]['type'] = $value_localpart;
					$this->message[$pos]['typePrefix'] = $value_prefix;
					if (isset($this->namespaces[$value_prefix])) {
						$this->message[$pos]['type_namespace'] = $this->namespaces[$value_prefix];
					} else if (isset($attrs['xmlns:' . $value_prefix])) {
						$this->message[$pos]['type_namespace'] = $attrs['xmlns:' . $value_prefix];
					}
					// should do something here with the namespace of specified type?
				}
			} else if ($key_localpart == 'arrayType') {
				$this->message[$pos]['type'] = 'array';
				/* do arrayType ereg here
				[1]    arrayTypeValue    ::=    atype asize
				[2]    atype    ::=    QName rank*
				[3]    rank    ::=    '[' (',')* ']'
				[4]    asize    ::=    '[' length~ ']'
				[5]    length    ::=    nextDimension* Digit+
				[6]    nextDimension    ::=    Digit+ ','
				*/
				$expr = '/([A-Za-z0-9_]+):([A-Za-z]+[A-Za-z0-9_]+)\[([0-9]+),?([0-9]*)\]/';
				if (preg_match($expr, $value, $regs)) {
					$this->message[$pos]['typePrefix'] = $regs[1];
					$this->message[$pos]['arrayTypePrefix'] = $regs[1];
					if (isset($this->namespaces[$regs[1]])) {
						$this->message[$pos]['arrayTypeNamespace'] = $this->namespaces[$regs[1]];
					} else if (isset($attrs['xmlns:' . $regs[1]])) {
						$this->message[$pos]['arrayTypeNamespace'] = $attrs['xmlns:' . $regs[1]];
					}
					$this->message[$pos]['arrayType'] = $regs[2];
					$this->message[$pos]['arraySize'] = $regs[3];
					$this->message[$pos]['arrayCols'] = $regs[4];
				}
				// specifies nil value (or not)
			} else if ($key_localpart === 'nil') {
				$this->message[$pos]['nil'] = (($value === 'true') || ($value === '1'));
				// some other attribute
			} else if (($key !== 'href')
				&& ($key !== 'xmlns')
				&& ($key_localpart !== 'encodingStyle')
				&& ($key_localpart !== 'root')) {
				$this->message[$pos]['xattrs']['!' . $key] = $value;
			}

			if ($key === 'xmlns') {
				$this->default_namespace = $value;
			}
			// log id
			if ($key === 'id') {
				$this->ids[$value] = $pos;
			}
			// root
			if (($key_localpart === 'root') && ($value == 1)) {
				$this->status = 'method';
				$this->root_struct_name = (string)$name;
				$this->root_struct = $pos;
				$this->debug("found root struct $this->root_struct_name, pos $pos");
			}
			// for doclit
			$attstr .= " $key=\"$value\"";
		}
		// get namespace - must be done after namespace atts are processed
		if (isset($prefix)) {
			$this->message[$pos]['namespace'] = $this->namespaces[$prefix];
			$this->default_namespace = $this->namespaces[$prefix];
		} else {
			$this->message[$pos]['namespace'] = $this->default_namespace;
		}
		if ($this->status === 'header') {
			if ($this->root_header != $pos) {
				$this->responseHeaders .= "<" . (isset($prefix) ? ($prefix . ':') : '') . "$name$attstr>";
			}
		} else if ($this->root_struct_name != '') {
			$this->document .= "<" . (isset($prefix) ? ($prefix . ':') : '') . "$name$attstr>";
		}
	}

	/**
	 * end-element handler
	 *
	 * @param    mixed $parser XML parser object
	 * @param    string $name element name
	 * @access   private
	 */
	public function end_element($parser, string $name)
	{
		// position of current element is equal to the last value left in depth_array for my depth
		$pos = $this->depth_array[$this->depth--];

		// get element prefix
		if (strpos($name, ':')) {
			// get ns prefix
			$prefix = substr($name, 0, strpos($name, ':'));
			// get unqualified name
			$name = substr(strstr($name, ':'), 1);
		}

		// build to native type
		if (isset($this->body_position) && ($pos > $this->body_position)) {
			// deal w/ multirefs
			if (isset($this->message[$pos]['attrs']['href'])) {
				// get id
				$id = substr($this->message[$pos]['attrs']['href'], 1);
				// add placeholder to href array
				$this->multirefs[$id][$pos] = 'placeholder';
				// add set a reference to it as the result value
				$this->message[$pos]['result'] =& $this->multirefs[$id][$pos];
				// build complexType values
			} else if ($this->message[$pos]['children'] != '') {
				// if result has already been generated (struct/array)
				if (!isset($this->message[$pos]['result'])) {
					$this->message[$pos]['result'] = $this->buildVal($pos);
				}
				// build complexType values of attributes and possibly simpleContent
			} else if (isset($this->message[$pos]['xattrs'])) {
				if (isset($this->message[$pos]['nil']) && $this->message[$pos]['nil']) {
					$this->message[$pos]['xattrs']['!'] = null;
				} else if (isset($this->message[$pos]['cdata']) && (trim($this->message[$pos]['cdata']) != '')) {
					if (isset($this->message[$pos]['type'])) {
						$this->message[$pos]['xattrs']['!'] = $this->decodeSimple($this->message[$pos]['cdata'], $this->message[$pos]['type'], isset($this->message[$pos]['type_namespace']) ? $this->message[$pos]['type_namespace'] : '');
					} else {
						$parent = $this->message[$pos]['parent'];
						if (isset($this->message[$parent]['type']) && ($this->message[$parent]['type'] === 'array') && isset($this->message[$parent]['arrayType'])) {
							$this->message[$pos]['xattrs']['!'] = $this->decodeSimple($this->message[$pos]['cdata'], $this->message[$parent]['arrayType'], isset($this->message[$parent]['arrayTypeNamespace']) ? $this->message[$parent]['arrayTypeNamespace'] : '');
						} else {
							$this->message[$pos]['xattrs']['!'] = $this->message[$pos]['cdata'];
						}
					}
				}
				$this->message[$pos]['result'] = $this->message[$pos]['xattrs'];
				// set value of simpleType (or nil complexType)
			} else {
				//$this->debug('adding data for scalar value '.$this->message[$pos]['name'].' of value '.$this->message[$pos]['cdata']);
				if (isset($this->message[$pos]['nil']) && $this->message[$pos]['nil']) {
					$this->message[$pos]['xattrs']['!'] = null;
				} else if (isset($this->message[$pos]['type'])) {
					$this->message[$pos]['result'] = $this->decodeSimple($this->message[$pos]['cdata'], $this->message[$pos]['type'], isset($this->message[$pos]['type_namespace']) ? $this->message[$pos]['type_namespace'] : '');
				} else {
					$parent = $this->message[$pos]['parent'];
					if (isset($this->message[$parent]['type']) && ($this->message[$parent]['type'] === 'array') && isset($this->message[$parent]['arrayType'])) {
						$this->message[$pos]['result'] = $this->decodeSimple($this->message[$pos]['cdata'], $this->message[$parent]['arrayType'], isset($this->message[$parent]['arrayTypeNamespace']) ? $this->message[$parent]['arrayTypeNamespace'] : '');
					} else {
						$this->message[$pos]['result'] = $this->message[$pos]['cdata'];
					}
				}

				/* add value to parent's result, if parent is struct/array
				$parent = $this->message[$pos]['parent'];
				if($this->message[$parent]['type'] != 'map'){
					if(strtolower($this->message[$parent]['type']) == 'array'){
						$this->message[$parent]['result'][] = $this->message[$pos]['result'];
					} else {
						$this->message[$parent]['result'][$this->message[$pos]['name']] = $this->message[$pos]['result'];
					}
				}
				*/
			}
		}

		// for doclit
		if ($this->status === 'header') {
			if ($this->root_header != $pos) {
				$this->responseHeaders .= "</" . (isset($prefix) ? ($prefix . ':') : '') . "$name>";
			}
		} else if ($pos >= $this->root_struct) {
			$this->document .= "</" . (isset($prefix) ? ($prefix . ':') : '') . "$name>";
		}
		// switch status
		if ($pos == $this->root_struct) {
			$this->status = 'body';
			$this->root_struct_namespace = $this->message[$pos]['namespace'];
		} else if ($pos == $this->root_header) {
			$this->status = 'envelope';
		} else if (($name === 'Body') && ($this->status === 'body')) {
			$this->status = 'envelope';
		} else if (($name === 'Header') && ($this->status === 'header')) { // will never happen
			$this->status = 'envelope';
		} else if (($name === 'Envelope') && ($this->status === 'envelope')) {
			$this->status = '';
		}
		// set parent back to my parent
		$this->parent = $this->message[$pos]['parent'];
	}

	/**
	 * element content handler
	 *
	 * @param    mixed $parser XML parser object
	 * @param    string $data element content
	 * @access   private
	 */
	public function character_data($parser, string $data)
	{
		$pos = $this->depth_array[$this->depth];
		if ($this->xml_encoding === 'UTF-8') {
			// TODO: add an option to disable this for folks who want
			// raw UTF-8 that, e.g., might not map to iso-8859-1
			// TODO: this can also be handled with xml_parser_set_option($this->parser, XML_OPTION_TARGET_ENCODING, "ISO-8859-1");
#ifndef KPHP
			if ($this->decode_utf8) {
				$data = utf8_decode($data);
			}
#endif
		}
		$this->message[$pos]['cdata'] .= $data;
		// for doclit
		if ($this->status === 'header') {
			$this->responseHeaders .= $data;
		} else {
			$this->document .= $data;
		}
	}

	/**
	 * get the parsed message (SOAP Body)
	 *
	 * @return    mixed
	 * @access   public
	 * @deprecated    use get_soapbody instead
	 */
	public function get_response()
	{
		return $this->soapresponse;
	}

	/**
	 * get the parsed SOAP Body (null if there was none)
	 *
	 * @return    mixed
	 * @access   public
	 */
	public function get_soapbody()
	{
		return $this->soapresponse;
	}

	/**
	 * get the parsed SOAP Header (null if there was none)
	 *
	 * @return    mixed|null
	 * @access   public
	 */
	public function get_soapheader()
	{
		return $this->soapheader;
	}

	/**
	 * get the unparsed SOAP Header
	 *
	 * @return    string XML or empty if no Header
	 * @access   public
	 */
	public function getHeaders()
	{
		return $this->responseHeaders;
	}

	/**
	 * decodes simple types into PHP variables
	 *
	 * @param    string $value value to decode
	 * @param    string $type XML type to decode
	 * @param    string $typens XML type namespace to decode
	 * @return    mixed PHP value
	 * @access   private
	 */
	public function decodeSimple($value, $type, $typens)
	{
		// TODO: use the namespace!
		if ((bool)$type || ($type === 'string') || ($type === 'long') || ($type === 'unsignedLong')) {
			return (string)$value;
		}
		if (($type === 'int') || ($type === 'integer') || ($type === 'short') || ($type === 'byte')) {
			return (int)$value;
		}
		if (($type === 'float') || ($type === 'double') || ($type === 'decimal')) {
			return (double)$value;
		}
		if ($type === 'boolean') {
			if ((strtolower($value) === 'false') || (strtolower($value) === 'f')) {
				return false;
			}
			return (bool)$value;
		}
		if (($type === 'base64') || ($type === 'base64Binary')) {
			$this->debug('Decode base64 value');
			return base64_decode($value);
		}
		// obscure numeric types
		if (($type === 'nonPositiveInteger') || ($type === 'negativeInteger')
			|| ($type === 'nonNegativeInteger') || ($type === 'positiveInteger')
			|| ($type === 'unsignedInt')
			|| ($type === 'unsignedShort') || ($type === 'unsignedByte')
		) {
			return (int)$value;
		}
		// bogus: parser treats array with no elements as a simple type
		if ($type === 'array') {
			return array();
		}
		// everything else
		return (string)$value;
	}

	/**
	 * builds response structures for compound values (arrays/structs)
	 * and scalars
	 *
	 * @param    int $pos position in node tree
	 * @return    mixed    PHP value
	 * @access   private
	 */
	public function buildVal(int $pos)
	{
		if (!isset($this->message[$pos]['type'])) {
			$this->message[$pos]['type'] = '';
		}
		$this->debug('in buildVal() for ' . $this->message[$pos]['name'] . "(pos $pos) of type " . $this->message[$pos]['type']);
		$params = [];

		// if there are children...
		if ($this->message[$pos]['children'] != '') {
			$params = [];
			$this->debug('in buildVal, there are children');
			$messageAtPos = $this->message[$pos];
			$children = explode('|', $messageAtPos['children']);
			array_shift($children); // knock off empty
			// md array
			if (isset($messageAtPos['arrayCols']) && ($messageAtPos['arrayCols'] != '')) {
				$r = 0; // rowcount
				$c = 0; // colcount
				foreach ($children as $child_pos) {
					$this->debug("in buildVal, got an MD array element: $r, $c");
					$params[$r][] = $this->message[$child_pos]['result'];
					$c++;
					if ($c == $messageAtPos['arrayCols']) {
						$c = 0;
						$r++;
					}
				}
				// array
			} else if (($messageAtPos['type'] === 'array')
				|| ($messageAtPos['type'] === 'Array')) {
				$this->debug('in buildVal, adding array ' . $messageAtPos['name']);
				foreach ($children as $child_pos) {
					//NOTE: was assign by reference
					$params[] = $this->message[$child_pos]['result'];
				}
				// apache Map type: java hashtable
			} else if (($messageAtPos['type'] === 'Map')
				&& ($messageAtPos['type_namespace'] === 'http://xml.apache.org/xml-soap')) {
				$this->debug('in buildVal, Java Map ' . $messageAtPos['name']);
				foreach ($children as $child_pos) {
					$kv = explode("|", $this->message[$child_pos]['children']);
					//NOTE: was assign by reference
					$params[$this->message[$kv[1]]['result']] = $this->message[$kv[2]]['result'];
				}
				// generic compound type
				//} elseif($this->message[$pos]['type'] == 'SOAPStruct' || $this->message[$pos]['type'] == 'struct') {
			} else {
				// Apache Vector type: treat as an array
				$this->debug('in buildVal, adding Java Vector or generic compound type ' . $messageAtPos['name']);
				if (($messageAtPos['type'] === 'Vector')
					&& ($messageAtPos['type_namespace'] === 'http://xml.apache.org/xml-soap')) {
					$notstruct = 1;
				} else {
					$notstruct = 0;
				}
				//
				foreach ($children as $child_pos) {
					if ($notstruct) {
						//NOTE: was assign by reference
						$params[] = $this->message[$child_pos]['result'];
						continue;
					}

					$messageNameAtChildPos = (string)$this->message[$child_pos]['name'];
					if (isset($params[$messageNameAtChildPos])) {
						// de-serialize repeated element name into an array
						if ((!is_array($params[$messageNameAtChildPos])) || (!isset($params[$messageNameAtChildPos][0]))) {
							$params[$messageNameAtChildPos] = array($params[$messageNameAtChildPos]);
						}
						//NOTE: was assign by reference
						$params[$messageNameAtChildPos][] = $this->message[$child_pos]['result'];
					} else {
						//NOTE: was assign by reference
						$params[$messageNameAtChildPos] = $this->message[$child_pos]['result'];
					}
				}
			}
			if (isset($messageAtPos['xattrs'])) {
				$this->debug('in buildVal, handling attributes');
				foreach ($this->message[$pos]['xattrs'] as $n => $v) {
					$params[$n] = $v;
				}
			}
			// handle simpleContent
			if (isset($messageAtPos['cdata']) && (trim($messageAtPos['cdata']) != '')) {
				$this->debug('in buildVal, handling simpleContent');
				if (isset($messageAtPos['type'])) {
					$params['!'] = $this->decodeSimple(
						(string)$messageAtPos['cdata'],
						(string)$messageAtPos['type'],
						(string)self::getProp($messageAtPos, 'type_namespace'));
				} else {
					$parent = $messageAtPos['parent'];
					$messageAtParent = $this->message[$parent];
					if (isset($messageAtParent['type'])
						&& ($messageAtParent['type'] === 'array')
						&& isset($messageAtParent['arrayType'])) {

						$params['!'] = $this->decodeSimple(
							(string)$messageAtPos['cdata'],
							(string)$messageAtParent['arrayType'],
							(string)self::getProp($messageAtParent, 'arrayTypeNamespace'));
					} else {
						$params['!'] = $messageAtPos['cdata'];
					}
				}
			}
			$ret = $params;
			$this->debug('in buildVal, return:');
			$this->appendDebug($this->varDump($ret));
			return $ret;
		}

		$this->debug('in buildVal, no children, building scalar');
		$messageAtPos = $this->message[$pos];
		$cdata = (string)self::getProp($messageAtPos, 'cdata');
		if (isset($messageAtPos['type'])) {
			$ret = $this->decodeSimple($cdata,
				(string)$messageAtPos['type'],
				(string)self::getProp($messageAtPos, 'type_namespace'));
			$this->debug("in buildVal, return: $ret");
			return $ret;
		}

		$parent = $messageAtPos['parent'];
		$messageAtParent = $this->message[$parent];
		if (isset($messageAtParent['type'])
			&& ($messageAtParent['type'] === 'array')
			&& isset($messageAtParent['arrayType'])) {
			$ret = $this->decodeSimple($cdata,
				(string)$messageAtParent['arrayType'],
				(string)self::getProp($messageAtParent, 'arrayTypeNamespace'));
			$this->debug("in buildVal, return: $ret");
			return $ret;
		}


		$ret = $messageAtPos['cdata'];
		$this->debug("in buildVal, return: $ret");
		return $ret;
	}
}