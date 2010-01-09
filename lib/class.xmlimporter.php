<?php
	
	require_once(TOOLKIT . '/class.fieldmanager.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	require_once(TOOLKIT . '/class.sectionmanager.php');
	
	require_once(EXTENSIONS . '/xmlimporter/lib/class.formatting_helpers.php');
	
	class XMLImporter {
		const __OK__ = 100;
		const __ERROR_PREPARING__ = 200;
		const __ERROR_VALIDATING__ = 210;
		const __ERROR_CREATING__ = 220;
		
		public $_Parent = null;
		public $_entries = array();
		public $_errors = array();
		
		public function __construct($parent) {
			$this->_Parent = $parent;
		}
		
		public function about() {
			return array();
		}
		
		public function getSection() {
			return null;
		}
		
		public function getRootExpression() {
			return '';
		}
		
		public function getUniqueField() {
			return '';
		}
		
		public function canUpdate() {
			return true;
		}
		
		public function getFieldMapping() {
			return array();
		}
		
		public function getEntries() {
			return $this->_entries;
		}
		
		public function getErrors() {
			return $this->_errors;
		}
		
		protected function getExpressionValue($xml, $entry, $xpath, $expression) {
			$matches = $xpath->evaluate($expression, $entry);

			if ($matches instanceof DOMNodeList) {
				$value = '';
				
				foreach ($matches as $match) {
					if ($match instanceof DOMAttr or $match instanceof DOMText) {
						$value .= $match->nodeValue;
					} else {
						$value .= $xml->saveXML($match);
					}
				}
				
				return $value;
				
			} else if (!is_null($matches)) {
				return (string)$matches;
			}
			
			return null;
		}
		
		public function validate($data) {
			if (!function_exists('handleXMLError')) {
				function handleXMLError($errno, $errstr, $errfile, $errline, $context) {
					$context['self']->_errors[] = $errstr;
				}
			}
			
			if (empty($data)) return null;
			
			$entryManager = new EntryManager($this->_Parent);
			$fieldManager = new FieldManager($this->_Parent);
			
			set_time_limit(900);
			set_error_handler('handleXMLError');
			
			$self = $this; // Fucking PHP...
			$xml = new DOMDocument();
			$xml->loadXML($data);
			
			restore_error_handler();
			
			$xpath = new DOMXPath($xml);
			$passed = true;
			
			// Invalid Markup:
			if (empty($xml)) {
				$passed = false;
				
			// Invalid Expression:
			} else if (($entries = $xpath->query(stripslashes($this->getRootExpression()))) === false) {
				$this->_errors[] = sprintf(
					'Root expression <code>%s</code> is invalid.',
					htmlentities(stripslashes($this->getRootExpression()), ENT_COMPAT, 'UTF-8')
				);
				$passed = false;
				
			// No Entries:
			} else if (empty($entries)) {
				$this->_errors[] = 'No entries to import.';
				$passed = false;
				
			// Test expressions:
			} else {
				foreach ($this->getFieldMapping() as $mapping) {
					
					if ($xpath->evaluate(stripslashes($mapping['xpath'])) === false) {
						$field = $fieldManager->fetch($mapping['field']);
						
						$this->_errors[] = sprintf(
							'\'%s\' expression <code>%s</code> is invalid.',
							$field->get('label'),
							htmlentities(stripslashes($mapping['xpath']), ENT_COMPAT, 'UTF-8')
						);
						$passed = false;
					}
				}
			}
			
			if (!$passed) return self::__ERROR_PREPARING__;
			
			// Gather data:
			foreach ($entries as $index => $entry) {

				$this->_entries[$index] = array(
					'element'	=> $entry,
					'values'	=> array(),
					'errors'	=> array(),
					'entry'		=> null
				);
				
				foreach ($this->getFieldMapping() as $mapping) {					
					$value = $this->getExpressionValue($xml, $entry, $xpath, $mapping['xpath'], $debug);
					if (isset($mapping['php']) && $mapping['php'] != '') {
						
						$php = stripslashes($mapping['php']);
						
						// static helper
						if (preg_match('/::/', $php)) {
							$value = call_user_func_array($php, array($value));
						}
						// basic function
						else {
							$function = preg_replace('/\$value/', "'" . $value . "'", $php);
							if (!preg_match('/^return/', $function)) $function = 'return ' . $function;
							if (!preg_match('/;$/', $function)) $function .= ';';
							$value = @eval($function);
						}
					}
					$this->_entries[$index]['values'][$mapping['field']] = $value;					
				}
			}
			
			// Validate:
			$passed = true;
			
			foreach ($this->_entries as &$current) {
				
				$entry = $entryManager->create();
				$entry->set('section_id', $this->getSection());
				$entry->set('author_id', $this->_Parent->Author->get('id'));
				$entry->set('creation_date', DateTimeObj::get('Y-m-d H:i:s'));
				$entry->set('creation_date_gmt', DateTimeObj::getGMT('Y-m-d H:i:s'));
				
				$values = array();
				
				// Map values:
				foreach ($current['values'] as $field_id => $value) {
					$field = $fieldManager->fetch($field_id);
					
					// Adjust value?
					if (method_exists($field, 'prepareImportValue')) {
						$value = $field->prepareImportValue($value);
					}
					
					$values[$field->get('element_name')] = $value;
				}
				
				// Validate:
				if (__ENTRY_FIELD_ERROR__ == $entry->checkPostData($values, $current['errors'])) {
					$passed = false;
					
				} elseif (__ENTRY_OK__ != $entry->setDataFromPost($values, $error, true)) {
					$passed = false;
				}
				
				$current['entry'] = $entry;
				$current['values'] = $values;
				
			}
			
			if (!$passed) return self::__ERROR_VALIDATING__;
			
			return self::__OK__;
		}
		
		public function commit() {
			// Find existing entries:
			
			$existing = array();
			
			if ($this->getUniqueField() != '') {
				$entryManager = new EntryManager($this->_Parent);
				$fieldManager = new FieldManager($this->_Parent);
				$field = $fieldManager->fetch($this->getUniqueField());
				
				if (!empty($field)) {
					foreach ($this->_entries as $index => $current) {
						
						$entry = $current['entry'];
						
						$data = $entry->getData($this->getUniqueField());
						$where = $joins = $group = null;
						
						$field->buildDSRetrivalSQL($data, $joins, $where);
						
						$group = $field->requiresSQLGrouping();
						$entries = $entryManager->fetch(null, $this->getSection(), 1, null, $where, $joins, false, true);						
						
						if (is_array($entries) && count($entries) > 0) {
							$existing[$index] = $entries[0]->get('id');
						} else {
							$existing[$index] = null;
						}
						
					}
				}
			}
			
			foreach ($this->_entries as $index => $current) {
				
				$entry = $current['entry'];
								
				// Matches an existing entry
				if (!empty($existing[$index])) {

					// update
					if ($this->canUpdate()) {
						$entry->set('id', $existing[$index]);
						$entry->set('importer_status', 'updated');
					}
					// skip
					else {
						$entry->set('importer_status', 'skipped');
						continue;
					}
				}
				
				$entry->commit();
				
				$status = $entry->get('importer_status');
				if (!$status) $entry->set('importer_status', 'created');

			}
		}
	}
	
?>
