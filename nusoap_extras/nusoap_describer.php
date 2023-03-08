<?php

/**
 * Class describes SOAP interface and can be used for generating class wrapper
 * or discovering API.
 * @author Alexey Vasilyev, Sigmalab LLC, 2023. node644@gmail.com
 * @license MIT, LGPL
 */
class nusoap_describer
{
	private const SEP = "  ";
	private const KEY_TYPE = "__type";

	/** Describe SOAP API as mixed structure.
	 * @param nusoap_wsdl $clientWsdl
	 * @return array
	 */
	public function describe(nusoap_wsdl $clientWsdl): array
	{
		$result = [];
		foreach ($clientWsdl->getOperations() as $operation) {
			$schemaParamType = $this->getOperationParamType($clientWsdl, $operation['input']['parts']['parameters']);
			$schemaResulType = $this->getOperationParamType($clientWsdl, $operation['output']['parts']['parameters']);

			$def = [
				"name"=>$operation['name'],
				"input"=>array_map(fn($arg) => $this->getTypeName($clientWsdl, (string)$arg['type'], self::SEP), $schemaParamType),
				"output"=>array_map(fn($arg) => $this->getTypeName($clientWsdl, (string)$arg['type'], self::SEP), $schemaResulType)
			];
			$result[$def['name']]= $def;
		}
		return $result;
	}

	/**
	 * @param mixed $mixed
	 *
	 */
	private function describeType($mixed, string $prefix=''):string
	{
		if ( !is_array($mixed)) return (string)$mixed;
		$result = [];
		$typeName = isset($mixed[self::KEY_TYPE]) ? $mixed[self::KEY_TYPE] : '';
		foreach ($mixed as $key=>$name){
			if  ($key === self::KEY_TYPE) continue;
			$result[] = "$key: ".$this->describeType($name, $prefix.self::SEP);
		}
		$text = implode("\n$prefix", $result);
		if (!$typeName ) return $text;
		return "$typeName = (\n$prefix". $text .")";
	}

	/** Describe SOAP API as text
	 * @param nusoap_wsdl $clientWsdl
	 * @return string
	 */
	public function describeAsText(nusoap_wsdl $clientWsdl): string
	{
		$interface = $this->describe($clientWsdl);
		$result = "";
		foreach ($interface as $method) {
			$result .="\n-------------------------------\n";
			$result .= "method: {$method['name']}\n\n"
			."input:\n".self::SEP . $this->describeType($method['input'], self::SEP)."\n\n"
			."output:\n".self::SEP . $this->describeType($method['output'], self::SEP)."\n\n";
		}
		return $result;
	}

	/**
	 * @param nusoap_wsdl $clientWsdl
	 * @param mixed  $inputType
	 * @return mixed
	 */
	private function getOperationParamType(nusoap_wsdl $clientWsdl, $inputType)
	{
		$paramType = explode(":", $inputType);
		$ns = $paramType[0] . ":" . $paramType[1];
		$schemaElementName = rtrim($paramType[2], "^");
		$schema = $clientWsdl->schemas[$ns][0];
		$element = $schema->elements[$schemaElementName];

		$schemaTypeName = explode(":", $element['type'])[2];
		if (!isset($schema->complexTypes[$schemaTypeName]['elements'])) {
			return [];
		}
		$schemaParamType = $schema->complexTypes[$schemaTypeName]['elements'];
		return $schemaParamType;
	}

	/**
	 * @param nusoap_wsdl $clientWsdl
	 * @param string $wsdlType
	 * @param string $prefix
	 * @return mixed
	 */
	private function getTypeName(nusoap_wsdl $clientWsdl, string $wsdlType, string $prefix = "")
	{
		static $stack = [];

		$paramType = explode(":", $wsdlType);
		if (count($paramType) !== 3) return $wsdlType;
		$ns = $paramType[0] . ":" . $paramType[1];
		$typeName = $paramType[2];
		if (!in_array($ns, [nusoap_xmlschema::STD_SCHEMA_1999,
			nusoap_xmlschema::STD_SCHEMA_2000, nusoap_xmlschema::STD_SCHEMA_2001])) {

			/** @var nusoap_xmlschema $schema */
			$schema = $clientWsdl->schemas[$ns][0];
			if (!isset($schema->complexTypes[$typeName])) {
				if (isset($schema->simpleTypes[$typeName])) {
					return $this->getTypeName($clientWsdl,
						(string)$schema->simpleTypes[$typeName]['type'], $prefix . self::SEP);
				}
				return $wsdlType;
			}

			$complexType = $schema->complexTypes[$typeName];
			if (!isset($complexType['elements'])) {
				return $wsdlType;
			}
			$pairs = [];
			foreach ($complexType['elements'] as $element) {
				if (!isset($element['type'])) continue;
				$type1 = $element['type'];
				if (isset($stack[$type1])) {
					$justTypeName = explode(":", $type1);
					return $justTypeName[2];
				}
				$stack[$type1] = $type1;
				$result = $this->getTypeName($clientWsdl, (string)$type1, $prefix . self::SEP);
				unset($stack[$type1]);
				$pairs[$element['name']] = $result;
			}
			return array_merge([self::KEY_TYPE=>$typeName], $pairs);
		}
		return rtrim($typeName, "^");
	}

}