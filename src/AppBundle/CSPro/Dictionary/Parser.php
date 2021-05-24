<?php
namespace AppBundle\CSPro\Dictionary;
use AppBundle\CSPro\Dictionary\Dictionary;
use AppBundle\CSPro\Dictionary\Level;
use AppBundle\CSPro\Dictionary\Record;
use AppBundle\CSPro\Dictionary\Item;
use AppBundle\CSPro\Dictionary\ValueSet;
use AppBundle\CSPro\Dictionary\Value;
use AppBundle\CSPro\Dictionary\ValuePair;

class Parser
{
	private $dictionaryStructure;

	/**
	* Create a new dictionary parser
	*
	*/
	function __construct() 
	{
		// Type converters: functions that convert string values in CSPro dcf file
		// to correct PHP type
		
		// Convert int string to PHP int
		$intType = function($s) {
			if (is_numeric($s)) 
				return intval($s); 
			else throw new \Exception("Not a number"); 
		};
		
		// String to string is no-op
		$stringType = function($s) {
			return $s;
		};
		
		// String to string is no-op
		$quoteDelimitedStringType = function($s) {
			if (preg_match("/^(?:'([^']*)')|(?:\"([^\"]*)\")$/", $s, $matches)) {
				return strlen($matches[1]) == 0 && count($matches) == 3 ? $matches[2] : $matches[1];
			} else {
				throw new \Exception("Invalid quote delimited string: $s");				
			}
		};
		
		// Convert Yes/No to boolean
		$boolType = function($s) {
			return strcasecmp($s, 'Yes') == 0;
		};
		
		// Check that value is in list of fixed options but leave as string since
		// PHP doesn't support enumerated types. Use this for 'Item'/'Subitem' and other
		// dictionary attributes that must be one of a list of values.
		$enumType = function($options) { 
						$optionsUpper = array_map('strtoupper', $options) ; 
						return function($s) use ($optionsUpper) {
							if (!in_array(strtoupper($s), $optionsUpper))
								throw new \Exception("Invalid value: $s Expected one of " . implode(',', $optionsUpper));
							return $s;
						};
					};
					
		// Check valid CSPro name (can only contain capital letters, digits and underscore)
		$nameType = function($s) {
			if (preg_match('/\A[A-Z0-9_]*\z/', $s)) 
				return $s; 
			else throw new \Exception("Invalid name: ".$s); 
		};
		
		// CSPro label which is actually a list of strings, one for each language.
		// In the dcf file the strings are separated by |.
		$labelType = function($s) {
			return explode('|', $s);
		};

		// Occurrence label: value, label
		$occLabelType = function($s) use ($labelType) { 
							if (preg_match("/^([^,]*),(.*)$/", $s, $matches)) 
								return array( $matches[1], $labelType($matches[2])); 
							else throw new \Exception("Invalid comma delimited pair");
						};

		// Value pair in a value set is either a single value or a range of values separated by a colon
		$vsetValuePairType = 
			function($s) {
				if (preg_match("/^([^:]*):(.*)$/", $s, $matches)) 
					return new ValuePair(array( 'From' => $matches[1], 'To' => $matches[2])); 
				else return new ValuePair(array('From' => $s)); 
			};
			
		// List of value pairs in a value set
		$vsetValuePairsType = function($s) use ($vsetValuePairType) {
			if ($s[0] == '"' || $s[0] == "'") {
				// alpha
				return $this->parseSpaceDelimitedQuotedStringList($s);
			} else {
				// numeric
				return array_map($vsetValuePairType, explode(' ', $s));
			}
		};
		
		// Single value in a value set: label followed by ; followed by value pairs
		$vsetValueType = 
			function($s) use ($labelType, $vsetValuePairsType) { 
				if (preg_match("/^([^;]*)(?:;(.*))?$/", $s, $matches)) {
					$values = $matches[1];
					$label = isset($matches[2]) ? $matches[2] : "";
					return array( 'Label' => $labelType($label), 'VPairs' => $vsetValuePairsType($values)); 
				} else
					throw new \Exception("Invalid value set value $s");
			};
			
		// Special value in a value set which is the name of the special value (NOTAPPL, DEFAULT, MISSING, REFUSED)
		// followed by a comma and the string "SPECIAL"
		$specialValueType =
			function ($s) use ($enumType) {
				if (preg_match("/^([^,]*),SPECIAL$/i", $s, $matches)) {
					if (in_array($matches[1], array('NOTAPPL', 'MISSING', 'DEFAULT', 'REFUSED'))) {
						return $matches[1];
					}
				}
				throw new \Exception("Invalid special value $s");
			};
		        
		// The dcf file is basically (but not exactly) an ini file (https://en.wikipedia.org/wiki/INI_file)
		// It is made up of sections that start with a heading name enclosed in []
		// Sections have attributes represented by a line in the file containing a command and 
		// and argument separated by an =. For example the following fragment of a dcf file
		// shows a section named "Item" with 3 attributes:
		//       [Item]
		//       Name=HOUSEHOLD_ID
		//       Label=Household identifier
		//       Length=4
		//
		// Sections can have subsections (child sections) and those subsections can have
		// their own subsections as well.
		
		// In order to parse the file we need to know which sections contain which subsections
		// and which attributes as well as the types of the different attributes. We represent this
		// as a PHP nested array. This array represents the top level [Dictionary] section
		// in the dcf file and lists the attributes and subsections of that section as well
		// as the attributes and subsections of each of the subsections and so on.
		// In some cases a subsection or an attributed can be repeated (items in a record, values in a
		// value set...). In this case we set the 'list' property of the subsection/attribute to true
		// and the parser will create an array out of the subsections/attributes.
		// By default the parser generates associative arrays for each section but by specifying abs
		// a constructor function it is possible to pass the associative array to this function
		// in order to convert it to a PHP class.
		// Attributes/subsections can be made required by setting the 'required' property to true or
		// they may be given a default value by setting the 'default' property. If a required
		// attribute/subsection is not found in the dcf file and exception is thrown. If an
		// attribute/subsection with a default value is not found then the default value is added to
		// the parent section.

		// Structure of common attributes used in multiple parts of dictionary
		$name = array('required' => true, 'type' => $nameType);
		$label = array('required' => true, 'type' => $labelType);
		$note = array('type' => $stringType, 'list' => true);
		
		// Item list is the same for id-items and for records
		// so we create it separately here and use it in both
		// places below.
        $itemList = array(
            'Item' => array(
				'constructor' => function ($a) {return new Item($a);},
                'list' => true,
				'required' => false,
				'default' => array(),
                'subsections' => array(
                    'ValueSet' => array(
						'constructor' => function ($a) {return new ValueSet($a);},
                        'list' => true,
						'default' => array(),
						'attributes' => array(
							'Name' => $name, 'Label' => $label, 'Note' => $note, 
							'Link' => array('type' => $nameType),
							'Value' => array('type' => $vsetValueType, 'list' => true,
											 'constructor' => function ($a) {return new Value($a);},
											 'default' => array(),
											 'subsections' =>
												array('attributes' => array(
													'Note' => array('type' => $stringType),
													'Name' => array('type' => $specialValueType, 'nameoverride' => 'Special'),
													'Image' => array('type' => $stringType)))
											)
						)
					)
                ),
				'attributes' => array(
					'Name' => $name, 'Label' => $label, 'Note' => $note, 
					'Start' => array('required' => true, 'type' => $intType), 
					'Len' => array('required' => true, 'type' => $intType), 
					'ItemType' => array('type' => $enumType(array('Item', 'Subitem')), 'default' => 'Item'),
					'DataType' => array('type' => $enumType(array('Numeric', 'Alpha')), 'default' => 'Numeric'),
					'Occurrences' => array('default' => 1, 'type' => $intType),
					'Decimal' => array('default' => 0, 'type' => $intType),
					'DecimalChar' => array('default' => false, 'type' => $boolType),
					'ZeroFill' => array('default' => false, 'type' => $boolType),
					'OccurrenceLabel' => array('type' => $occLabelType, 'list' => true, 'default' => array()))
			)
		);
		
		// Main dictionary structure.
        $this->dictionaryStructure = array(
				'constructor' => function ($a) {return new Dictionary($a);},
				'attributes' => array(
					'Name' => $name, 'Label' => $label, 'Note' => $note, 
					'Version' => array('type' => $stringType, 'required' => true), 
					'RecordTypeStart' => array('required' => true, 'type' => $intType),
					'RecordTypeLen' => array('required' => true, 'type' => $intType),
					'Positions' => array('required' => true, 'type' => $enumType(array('Relative', 'Absolute'))),
					'ZeroFill' => array('required' => true, 'type' => $boolType),
					'DecimalChar' => array('required' => true, 'type' => $boolType),
				),
                'subsections' => array(
					'Languages' => array(
						'constructor' => function ($a) { return array_map(function ($name, $label) { return new Language($name, $label);}, array_keys($a), $a); },
						'default' => array(),
						'attributes' => array(
							'*' => array('type' => $stringType)
						)
					),
					'Relation' => array(
						'constructor' => function ($a) { return new Relation($a); },
						'list' => true,
						'default' => array(),
						'attributes' => array(
							'Name' => $name,
							'Primary' => array('required' => true, 'type' => $nameType),
							'PrimaryLink' => array('default' => null, 'type' => $nameType),
							'Secondary' => array('required' => true, 'type' => $nameType),
							'SecondaryLink' => array('default' => null, 'type' => $nameType)
						)
					),
                    'Level' => array(
						'constructor' => function ($a) {return new Level($a);},
                        'list' => true,
						'required' => true,
						'attributes' => array('Name' => $name, 'Label' => $label, 'Note' => $note), 
                        'subsections' => array(
                            'IdItems' => array(
								'constructor' => function ($a) {return $a['Item'];},
                                'subsections' => $itemList,
								'attributes' => array()
                            ),
                            'Record' => array(
								'constructor' => function ($a) {return new Record($a);},
                                'list' => true,
								'required' => true,
                                'subsections' => $itemList,
								'attributes' => array(
									'Name' => $name, 'Label' => $label, 'Note' => $note, 
									'RecordTypeValue' => array('type' => $quoteDelimitedStringType, 'required' => true), 
									'Required' => array('type' => $boolType, 'default' => false), 
									'MaxRecords' => array('type' => $intType, 'default' => 1), 
									'RecordLen' => array('type' => $intType, 'required' => true), 
									'OccurrenceLabel' => array('type' => $occLabelType, 'list'=> true, 'default' => array())))
                            )
                        )
                    )
                );
	}

	/**
    * Parse CSPro dictionary (dcf) text file and return a Dictionary object
	*
	* @param	string		Contents of dictionary in dcf format
	* @return	Dictionary	$dictText CSPro Dictionary object
	* @throws	Exception	if the $dictText is not a proper CSPro dictionary
	*/
    public function parseDictionary($dictText)
    {
        // Remove the byte order marker
        $bom      = pack('H*', 'EFBBBF');
        $dictText = preg_replace("/^$bom/", '', $dictText);
		
        $lines = explode("\n", $dictText);
		
        if (trim($lines[0]) != '[Dictionary]')
			throw new \Exception('Invalid dictionary - does not start with [Dictionary]');
		
        $lineNo = 1;
        $dict = $this->parseSection($lines, $lineNo, $this->dictionaryStructure);
		
		// If we parsed the dictionary but there are still lines we haven't
		// processed then there is extra stuff at the end of the dictionary
		// which we can't handle and this is an error.
		if ($lineNo < count($lines)) {
			throw new \Exception('Invalid command on line ' . ($lineNo + 1) . ': ' . $lines[$lineNo]);
		}
		
        return $dict;
    }
    
	/**
	* Parse one section (and child subsections) of dictionary
	*
	* Matches the lines in $lines against attributes and subsections in $sectionStructure
	* to build the appropriate object(s) for the parsed section. Makes recursive calls to
	* to build subsections.
	*
	* @param	string[]	lines				Array of lines for the entire dcf file
	* @param	int		 	lineNo				Starting line number in $lines for section to parse (incremented as section is parsed) 
	* @param	mixed[]		sectionStructure	Associative array representing structure for this section and subsections
	* @return	mixed		Parsed section and subsections
	* @throws	Exception	If $lines does not match the structure from $sectionStructure
	*/	
    private function parseSection($lines, &$lineNo, $sectionStructure)
    {		
		$startLine = $lineNo;
        $currentSection = array();
		
        while ($lineNo < count($lines)) {
            $line = trim($lines[$lineNo]);
			
			// Skip over blank lines
            if ($line == "") {
                $lineNo++;
                continue;
            }
			
            if ($this->parseSectionHeader($line, $sectionName)) {
                // new section
                if (isset($sectionStructure['subsections']) && isset($sectionStructure['subsections'][$sectionName])) {
                    $subsectionStructure = $sectionStructure['subsections'][$sectionName];
					$this->handleSubsection($lines, $lineNo, $sectionName, $subsectionStructure, $currentSection);
                } else {
                    // Not a child section, our section is done
					break;
                }
            } else if ($this->parseCmdArg($line, $cmd, $arg)) {
				// Attribute in current section
				if (isset($sectionStructure['attributes'][$cmd])) {
					$attribute = $sectionStructure['attributes'][$cmd];
					$this->handleAttribute($lines, $lineNo, $attribute, $cmd, $arg, $currentSection);
				} else if (isset($sectionStructure['attributes']['*'])) {
					// Section has wildcard attribute
					$attribute = $sectionStructure['attributes']['*'];
					$this->handleAttribute($lines, $lineNo, $attribute, $cmd, $arg, $currentSection);
				} else {
					// skip unknown attribute
					$lineNo++;
				}
            } else {
				throw new \Exception('Invalid format on line ' . ($lineNo + 1) . ': ' . $line);
            }
        }
        
		// Ensure that all required attributes are present and fill in defaults for non-required
		foreach ($sectionStructure['attributes'] as $attributeName => $attribute) {
			if (!isset($currentSection[$attributeName])) {
				if (isset($attribute['required']) && $attribute['required'])
					throw new \Exception("Section starting on line $startLine missing required attribute: $attributeName");
				if (isset($attribute['default']))
					$currentSection[$attributeName] = $attribute['default'];
			}
		}
		
		// Ensure that all required subsections are present and fill in defaults for non-required
		if (isset($sectionStructure['subsections'])) {
			foreach ($sectionStructure['subsections'] as $childName => $child) {
				if (!isset($currentSection[$childName])) {
					if (isset($child['required']) && $child['required'])
						throw new \Exception("Section starting on line $startLine missing required child section: $childName");
					if (isset($child['default']))
						$currentSection[$childName] = $child['default'];
				}
			}
		}
		
		if (isset($sectionStructure['constructor'])) {
			return $sectionStructure['constructor']($currentSection);
		} else {
			return $currentSection;
		}
			
    }
	
	/**
	* Parse a sub-section
	*
	* @param	string[]	lines				Array of lines for the entire dcf file
	* @param	int		 	lineNo				Starting line number in $lines for section to parse (incremented as section is parsed)
	* @param	string		sectionName			Name of subsection (what is between [] in section header)
	* @param	mixed[]		subsectionStructure	Associative array representing structure for this subsection
	* @param	mixed[]		currentSection		Parent section, parsed subsection is added to $currentSection
	* @throws	Exception	If $lines does not match the structure from $subsectionStructure
	*/	
	private function handleSubsection($lines, &$lineNo, $sectionName, $subsectionStructure, &$currentSection)
	{
        $lineNo++;
		$subsection = $this->parseSection($lines, $lineNo, $subsectionStructure);
		if (isset($subsectionStructure['list']) && $subsectionStructure['list']) {
			$this->addToList($currentSection, $sectionName, $subsection);
		} else {
			$currentSection[$sectionName] = $subsection;
		}
	}
	
	/**
	* Parse an attribute in a section
	*
	* @param	string[]	lines				Array of lines for the entire dcf file
	* @param	int		 	lineNo				Starting line number in $lines for section to parse (incremented as section is parsed)
	* @param	mixed[]		attribute			Associative array of attribute properties from dictionary structure
	* @param	string		cmd					Command (tag) for the attribute (part before =)
	* @param	string 		arg					Argument (value) for the attribute (part after =)
	* @param	mixed[]		currentSection		Parent section of attribute, $currentSection[$cmd] is set to parsed attribute 
	* @throws	Exception	If $lines does not match the structure from $subsectionStructure
	*/	
	private function handleAttribute($lines, &$lineNo, $attribute, $cmd, $arg, &$currentSection)
	{
		// Call the type converter function for the attribute
		try {
			$converter = $attribute['type']; 
			$typedArg = $converter($arg);
		} catch(Exception $e) {
			throw new \Exception('Invalid argument '. $arg .' for command '. $cmd .' on line ' . ($lineNo + 1));
		}

		$isList = isset($attribute['list']) && $attribute['list'];
		
		$name = isset($attribute['nameoverride']) ? $attribute['nameoverride'] : $cmd;
		
		if ($isList) {
			$this->addToList($currentSection, $name, $typedArg);
		} else {
			$currentSection[$name] = $typedArg;
		}
		$lineNo++;
		
		// Attributes can have subsections too (see value set values), in which
		// case we merge the attributes of the child section with the attributes
		// of the parent attribute.
		if (isset($attribute['subsections']) && count($attribute['subsections']) > 0) {
			$child = $this->parseSection($lines, $lineNo, $attribute['subsections']);
			if ($isList) {
				// If the parent attribute is a list we merge with the last value in 
				// the list since this is the real parent.
				$last = count($currentSection[$name]) - 1;
				$currentSection[$name][$last] = array_merge($currentSection[$name][$last], $child);
			} else {
				// Not a list, merge with parent.
				$currentSection[$name] = array_merge($currentSection[$name], $child);						
			}
		}
	}
	    
	private function parseSectionHeader($line, &$sectionName)
	{
		if (preg_match("/^\[(.*)\]$/", $line, $matches)) {
			$sectionName = $matches[1];
			return true;
		}
		return false;
	}
	
	private function parseCmdArg($line, &$cmd, &$arg)
	{
        if (preg_match("/^([^=]*)=(.*)$/", $line, $matches)) {
			// attribute of current section
			$cmd = $matches[1];
			$arg = $matches[2];
			return true;
		}
		return false;
	}
	
	private function addToList(&$parent, $index, $child)
	{
		if (!isset($parent[$index]))
			$parent[$index] = array($child);
		else
			array_push($parent[$index], $child);
	}

	private function parseSpaceDelimitedQuotedStringList($s)
	{
		$result = array();
		$rest = $s;
		while (preg_match("/^(?:'([^']*)')|(?:\"([^\"]*)\")/", $rest, $matches)) {
			$match = strlen($matches[1]) == 0 ? $matches[2] : $matches[1];			
			array_push($result, $match);
			$rest = substr($rest, strlen($match) + 2);
			if (strlen($rest) == 0)
				break;
			if ($rest[0] != ' ')
				throw new \Exception("Invalid space delimited list, expected space in $s");
			$rest = substr($rest, 1);
		}
		if (strlen($rest) > 0)
			throw new \Exception("Invalid space delimited list, item is not quoted string in $s");
		return $result;
	}
}
