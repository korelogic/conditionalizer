<?php

	Class fieldFilter extends Field {

		// We cache XML and values at checkPostFieldData() and clear it after processRawFieldData().
		// That way all Filter fields for the same entry can use the same XML
		// (no need to regenerate whole thing for each filter field in the section).
		// WARNING: this trick will be broken if something will call checkPostFieldData() for different entries,
		//          and call processRawFieldData() only after that. As far as i know nothing does such thing (yet?).
		static $xpath;
		static $results;

		static $recursion;
		static $processed;

		public function __construct(&$parent) {
			parent::__construct($parent);
			$this->_name = __('Filter');
			$this->_required = false;
			$this->_showcolumn = false;

			// Set defaults
			$this->set('show_column', 'no');
			$this->set('required', 'yes');
		}

		/*
		**	Implementation of Symphony API
		*/

		// Specify if field value can be set from entry list table, i.e., section content page
		public function canToggle() {
			return false;
		}

		// Specify if entries can be filtered by this field
		public function canFilter() {
			return ($this->get('filter_datasource') == 'yes');
		}

		// Specify if field can be prepopulated through GET?
		public function canPrePopulate() {
			return false;
		}

		// Specify if entries can be sorted by this field
		public function isSortable() {
			return true;
		}

		// Specify if entries can be grouped by field when listed in XML
		public function allowDatasourceOutputGrouping() {
			return false;
		}

		// Specify if field requires grouping in SQL select (to avoid duplicate entry rows)
		public function requiresSQLGrouping() {
			return false;
		}

		// Specify if field can output it's value as XSLT parameter
		public function allowDatasourceParamOutput() {
			return false;
		}

		// Generate XML data containing field values
		public function appendFormattedElement(&$wrapper, $data, $encode = false) {
			$value = ($data['value'] == 'yes' ? 'Yes' : 'No');
			$wrapper->appendChild(new XMLElement($this->get('element_name'), ($encode ? General::sanitize($value) : $value)));
		}

		// Generate groups which will be used by Datasource to when generating XML
		// Entries with the same value of this field will be wrapped field tag
		// (so it is not grouping in the same sense as SQL grouping! :)
		public function groupRecords($records) {
			return;
		}

		// Build field widget for entry edit page
		public function displayPublishPanel(&$wrapper, $data = NULL, $flagWithError = NULL, $fieldnamePrefix = NULL, $fieldnamePostfix = NULL) {
		}

		// Generate field widget used by Events when rendering example markup
		public function getExampleFormMarkup() {
			return new XMLElement('!--', ' --');
		}

		// Build "filter by field" widget for Datasource edit page
		public function displayDatasourceFilterPanel(&$wrapper, $data = NULL, $errors = NULL, $fieldnamePrefix = NULL, $fieldnamePostfix = NULL) {
			if ($data == NULL) $data = '(if value of (param_or_value_here) is (param_or_value_here))';

			$e = $this->parseExpression($data);
			if (empty($e)) {
				// Strip all parameters just to see if there was invalid expression there or none at all
				$e = preg_replace(array('@{(([^}:]+)|[^}]+?:([^}:]+))?}@i', '@{\$[^}]+}@i', '@{([^}\$]+)}@i'), array('{$2$3}', 'yes', '$1'), $data);
				$e = array_map('trim', preg_split('/(?<!\\\\)[,\+] /', $e, -1, PREG_SPLIT_NO_EMPTY));
				if (!empty($e)) {
					$e = array_diff($e, array('yes', 'no'));
					// Make array non-empty if all values are either 'yes' or 'no', otherwise make it empty to mark wrong syntax
					$e = (count($e) > 0 ? array() : array('ok'));
				}
			}

			// Copy content generated by parent::displayDatasourceFilterPanel(), so we can wrap it with error if needed
			$wrapper->appendChild(new XMLElement('h4', $this->get('label') . ' <i>'.$this->Name().'</i>'));
			$label = Widget::Label(__('Value'));
			$label->appendChild(Widget::Input('fields[filter]'.($fieldnamePrefix ? '['.$fieldnamePrefix.']' : '').'['.$this->get('id').']'.($fieldnamePostfix ? '['.$fieldnamePostfix.']' : ''), ($data ? General::sanitize($data) : NULL)));
			$wrapper->appendChild((empty($e) ? Widget::wrapFormElementWithError($label, __('Invalid syntax')) : $label));

			$params = $this->listParams();
			if (empty($params)) return;

			$optionlist = new XMLElement('ul');
			$optionlist->setAttribute('class', 'tags');

			$optionlist->appendChild(new XMLElement('li', 'yes', array('title' => 'Exact string value')));
			$optionlist->appendChild(new XMLElement('li', 'no', array('title' => 'Exact string value')));

			foreach ($params as $param => $value) {
				$optionlist->appendChild(new XMLElement('li', $param, array('class' => '{$'.$param.'}', 'title' => ($value ? __('Value of %s returned from another data source', array($value)) : __('Value found in URL path')))));
			}

			$wrapper->appendChild($optionlist);
		}

		// Render value which will be used in entry list table (on section content page)
		public function prepareTableValue($data, XMLElement $link = NULL) {
			return (empty($data['value']) || $data['value'] == 'yes' ? __('Yes') : __('No'));
		}

		// Prepare default values for field settings widget (used on section edit page)
		public function findDefaults(&$fields) {
			if (!isset($fields['filter_publish'])) $fields['filter_publish'] = '';
			if (!isset($fields['filter_publish_errors'])) $fields['filter_publish_errors'] = 'no';
			if (!isset($fields['filter_datasource'])) $fields['filter_datasource'] = 'no';
		}

		// Build field settings widget used on section edit page
		public function displaySettingsPanel(&$wrapper, $errors = NULL) {
			parent::displaySettingsPanel($wrapper, $errors);

			// Disable/Enable publish filtering
			$label = Widget::Label(__('Value filter expression'));
			$label->appendChild(new XMLElement('i', __('Optional')));
			$input = Widget::Input('fields['.$this->get('sortorder').'][filter_publish]', $this->get('filter_publish'));
			$label->appendChild($input);
			if(isset($errors['filter_publish'])) $wrapper->appendChild(Widget::wrapFormElementWithError($label, $errors['filter_publish']));
			else $wrapper->appendChild($label);
			$wrapper->appendChild(new XMLElement('p', __('Default value of this field will be set to <code>yes</code>. If expression above will evaluate to <code>false</code>, value of this field will be set to <code>no</code>. Use <code>{XPath}</code> syntax to put values into expression before it will be evaluated, e.g., to make use of value from HTML element called "<code>fields[published]</code>" enter "<code>{post/published}</code>".'), array('class' => 'help')));

			// Disable/Enable publish error when evaluated expression returns false
			$label = Widget::Label();
			$input = Widget::Input('fields['.$this->get('sortorder').'][filter_publish_errors]', 'yes', 'checkbox');
			if ($this->get('filter_publish_errors') == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue(__('%s Allow saving an entry only if expression entered above evaluates to true', array($input->generate())));
			$wrapper->appendChild($label);

			// Disable/Enable datasource filtering
			$label = Widget::Label();
			$input = Widget::Input('fields['.$this->get('sortorder').'][filter_datasource]', 'yes', 'checkbox');
			if ($this->get('filter_datasource') == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue(__('%s Allow Data Sources to filter this section with an expression', array($input->generate())));
			$wrapper->appendChild($label);
		}

		// Check if field settings data is valid
		public function checkFields(Array &$errors, $checkForDuplicates = true) {

			$expression = trim($this->get('filter_publish'));
			if (!empty($expression)) {
				$r = $this->parseExpression($expression);
				if (empty($r)) $errors['filter_publish'] = __('Invalid syntax');
			}

			return parent::checkFields($errors, $checkForDuplicates);
		}

		// Check if publish data is valid
		public function checkPostFieldData($data, &$message, $entry_id = NULL) {
			if (!($expression = trim($this->get('filter_publish'))) || self::$recursion == true) return self::__OK__;

			// Clear XPath cache if processRawFieldData() was run
			// (which means all fields were checked and processed, and now new checks are run for new entry)
			if (self::$processed == true) {
				self::$xpath = NULL;
				self::$results = array();
				self::$processed = false;
			}

			// Preprocess expression
			if (preg_match_all('@{([^}]+)}@i', $expression, $matches, PREG_SET_ORDER)) {
				$xpath = $this->getXPath($entry_id);
				foreach ($matches as $m) {
					$v = @$xpath->evaluate("string({$m[1]})");
					if (is_null($v)) {
						$expression = str_replace($m[0], '', $expression);
					}
					else {
						$expression = str_replace($m[0], $v, $expression);
					}
				}
			}

			// Evaluate expression and return error if it returns false
			$result = self::__OK__;
			$message = NULL;
			if (!$this->evaluateExpression($expression)) {
				$message = __("Contains invalid data.");
				$result = self::__INVALID_FIELDS__;
			}

			self::$results[$this->get('id')] = $result;
			if ($this->get('filter_publish_errors') == 'yes') return $result;

			return self::__OK__;		
		}

		// Prepare value to be saved to database
		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = NULL) {
			if ($simulate != true) self::$processed = true;
			$status = self::__OK__;
			return array('value' => (self::$results[$this->get('id')] == self::__INVALID_FIELDS__ ? 'no' : 'yes'));
		}

		// Prepare SQL part responsible for sorting entries by this field
		public function buildSortingSQL(&$joins, &$where, &$sort, $order = 'ASC') {
			$joins .= "LEFT OUTER JOIN `tbl_entries_data_".$this->get('id')."` AS `ed` ON (`e`.`id` = `ed`.`entry_id`) ";
			$sort = 'ORDER BY ' . (in_array(strtolower($order), array('random', 'rand')) ? 'RAND()' : "`ed`.`value` $order");
		}

		// Prepare SQL part responsible for filtering entries by this field
		public function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation = false) {

			// If filtering is enabled, return value of evaluated expression
			if ($this->get('filter_datasource') == 'yes') {
				// Glue $data back if it was split by data source
				if (is_array($data)) $data = implode(($andOperation ? '+ ' : ', '), $data);

				// Block data source if there was a valid expression and it did not evaluate to true
				$e = $this->parseExpression($data);
				if (!empty($e) && !$this->evaluateExpression($e)) {
					return false;
				}

				// If expression evaluated to true, remove it from data and see if we need to filter entries by our field value
				if (!empty($e)) {
					$data = ltrim(str_replace($e[0], '', $data), ($andOperation ? '+ ': ', '));
				}

				// Return true if there is nothing left in $data
				if (empty($data)) return true;

				// Split $data back to array
				$data = preg_split('/'.($andOperation ? '\+' : '(?<!\\\\),').'\s*/', $data, -1, PREG_SPLIT_NO_EMPTY);
				$data = array_map('trim', $data);

				// Block data source if not all values are either 'yes' or 'no'
				// If there were wrong parameter values, or invalid expression, this will make data souce blocked "by default"
				$e = array_diff($data, array('yes', 'no'));
				if (!empty($e)) return false;
			}

			// Filtering by expression was disabled, so perform regular filtering by "yes" and/or "no"
			$field_id = $this->get('id');
			if (!is_array($data)) $data = array($data);
			if ($andOperation) {
				foreach ($data as $value) {
					$this->_key++;
					$or = ($value == 'yes' ? " OR t{$field_id}_{$this->_key}.value IS NULL " : '');
					$value = $this->cleanValue($value);
					$joins .= " LEFT JOIN `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key} ON (e.id = t{$field_id}_{$this->_key}.entry_id) ";
					$where .= " AND (t{$field_id}_{$this->_key}.value = '{$value}'{$or}) ";
				}
			} else {
				$this->_key++;
				$or = (in_array('yes', $data) ? " OR t{$field_id}_{$this->_key}.value IS NULL " : '');
				$data = implode("', '", array_map(array($this, 'cleanValue'), $data));
				$joins .= " LEFT JOIN `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key} ON (e.id = t{$field_id}_{$this->_key}.entry_id) ";
				$where .= " AND (t{$field_id}_{$this->_key}.value IN ('{$data}'){$or}) ";
			}

			return true;
		}

		// Save field settings (edited on section edit page) to database
		public function commit() {
			if (!parent::commit()) return false;

			$id = $this->get('id');

			if ($id === false) return false;

			$fields = array();
			$fields['field_id'] = $id;
			$fields['filter_publish'] = trim($this->get('filter_publish'));
			$fields['filter_publish_errors'] = ($this->get('filter_publish_errors') == 'yes' ? 'yes' : 'no');
			$fields['filter_datasource'] = ($this->get('filter_datasource') == 'yes' ? 'yes' : 'no');

			$this->Database->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '{$id}'");

			return $this->Database->insert($fields, 'tbl_fields_' . $this->handle());
		}

		// Create database table which will keep field values for each entry
		public function createTable() {
			return Symphony::Database()->query(
				'CREATE TABLE IF NOT EXISTS `tbl_entries_data_'.$this->get('id').'` (
					`id` int(11) unsigned NOT NULL auto_increment,
					`entry_id` int(11) unsigned NOT NULL,
					`value` enum(\'yes\',\'no\') DEFAULT \'yes\',
					PRIMARY KEY (`id`),
					KEY `entry_id` (`entry_id`)
				) TYPE=MyISAM;'
			);
		}

		/*
		**	Own stuff
		*/

		// Get list of page and datasource params
		private function listParams() {
			$params = array();

			// Get page params
			$Admin = Administration::instance();
			$pages = $Admin->Database->fetch('SELECT params FROM tbl_pages WHERE params IS NOT NULL');
			if (is_array($pages) && !empty($pages)) {
				foreach ($pages as $page => $data) {
					if (($data = trim($data['params']))) {
						foreach (explode('/', $data) as $p) {
							$params['$'.$p] = '';
						}
					}
				}
			}

			// Get datasource generated params
			$files = General::listStructure(DATASOURCES, array('php'), false, 'asc');
			if (!empty($files['filelist'])) {
				foreach ($files['filelist'] as $file) {
					$data = file_get_contents(DATASOURCES."/{$file}");
					if (strpos($data, 'include(TOOLKIT . \'/data-sources/datasource.section.php\');') === false) continue;

					if (preg_match('/\s+public\s*\$dsParamPARAMOUTPUT\s*=\s*([\'"])([^\1]+)(?:\1)\s*;/U', $data, $m)) {
						$p = $m[2];
						if (preg_match('/\s+public\s*\$dsParamROOTELEMENT\s*=\s*([\'"])([^\1]+)(?:\1)\s*;/U', $data, $m)) {
							$params['$ds-'.$m[2]] = $p;
						}
					}
				}
			}

			return $params;
		}

		// Check syntax
		private function parseExpression($e) {
			/*
				Valid expression should result in array(
					0 => whole expression,
					1 => function,
					2 => param,
					3 => operand,
					4 => param,
				);
			*/
			if (!preg_match('/(?:^[^\(]*)(\(if\s+(value of|any of|all of)\s*(\((?:[^\(\)]+|(?3))*\))\s+((?:is|are)(?: not|)(?: in|))\s*(\((?:[^\(\)]+|(?5))*\))\s*\))/', $e, $r))
				return array();
			
			array_shift($r); // Remove $r[0] that contains data which we do not want
			return $r;
		}

		// Evaluate expression to boolean value
		private function evaluateExpression($e) {
			// (if value of ({$ds-value}) is (one))
			// (if value of ({$ds-value}) is not ())
			// (if any of ({$ds-value}) is in (one,two,three))
			// (if all of ({$ds-value}) are in (one,two,three))
			// (if any of ((if value of ({$ds-value}) is (one)), ({$ds-is-true})) is (false))

			if (is_array($e)) $r = $e; // Recursive call for sub expression, $e is already parsed
			else $r = $this->parseExpression($e);

			if (empty($r)) return false;

			$r[2] = substr($r[2], 1, -1); // Remove first level parenthesis
			$r[4] = substr($r[4], 1, -1); // Same here

			// Parse sub expressions
			for ($i = 2; $i <= 4; $i+=2) {
				$max = 10;
				while ($max--) {
					$s = $this->parseExpression($r[$i]);
					if (empty($s)) break;

					$r[$i] = str_replace($s[0], ($this->evaluateExpression($s) ? 'yes' : 'no'), $r[$i]);
				}
			}

			switch ($r[3]) {
				case 'is in':
				case 'are in':
					if ((!$r[2] || !$r[4]) && $r[2] != $r[4]) return false;

					$r[2] = preg_split('/,\s*/', $r[2]);
					$r[4] = preg_split('/,\s*/', $r[4]);
					$found = array_intersect($r[2], $r[4]);
					if ($r[1] == 'value of' || $r[1] == 'all of') {
						return (!empty($found) && count($r[2]) >= count($found) && count($r[2]) <= count($r[4]));
					}
					else if ($r[1] == 'any of') {
						return (!empty($found));
					}
					break;

				case 'is not in':
				case 'are not in':
					if ((!$r[2] || !$r[4]) && $r[2] != $r[4]) return true;

					$r[2] = preg_split('/,\s*/', $r[2]);
					$r[4] = preg_split('/,\s*/', $r[4]);
					$found = array_intersect($r[2], $r[4]);
					if ($r[1] == 'value of' || $r[1] == 'all of') {
						return (empty($found));
					}
					else if ($r[1] == 'any of') {
						return (empty($r[4]) || count($found) < count($r[2]));
					}
					break;

				case 'is not':
					if ($r[1] == 'value of') {
						return ($r[2] != $r[4]);
					}
					else if ($r[1] == 'any of') {
						foreach (preg_split('/,\s*/', $r[2]) as $v) {
							if ($v != $r[4]) return true;
						}
						return false;
					}
					else if ($r[1] == 'all of') {
						foreach (preg_split('/,\s*/', $r[2]) as $v) {
							if ($v == $r[4]) return false;
						}
					}
					break;

				case 'is':
					if ($r[1] == 'value of') {
						return ($r[2] == $r[4]);
					}
					else if ($r[1] == 'any of') {
						foreach (preg_split('/,\s*/', $r[2]) as $v) {
							if ($v == $r[4]) return true;
						}
						return false;
					}
					else if ($r[1] == 'all of') {
						foreach (preg_split('/,\s*/', $r[2]) as $v) {
							if ($v != $r[4]) return false;
						}
					}
					break;
			}

			return true;
		}

		// From Reflection field extension:
		// http://symphony-cms.com/download/extensions/view/20737/
		// https://github.com/rowan-lewis/reflectionfield
		// We split getXPath() function into appendEntryXML() and getXPath()
		private function appendEntryXML(&$wrapper, &$fieldManager, $entry) {
			$section_id = $entry->get('section_id');
			$data = $entry->getData();
			$fields = array();

			$wrapper->setAttribute('id', $entry->get('id'));

			$associated = $entry->fetchAllAssociatedEntryCounts();

			if (is_array($associated) and !empty($associated)) {
				foreach ($associated as $section => $count) {
					$handle = Symphony::Database()->fetchVar('handle', 0, "
						SELECT
							s.handle
						FROM
							`tbl_sections` AS s
						WHERE
							s.id = '{$section}'
						LIMIT 1
					");

					$wrapper->setAttribute($handle, (string)$count);
				}
			}

			// Add fields:
			foreach ($data as $field_id => $values) {
				if (empty($field_id)) continue;

				$field = $fieldManager->fetch($field_id);
				$field->appendFormattedElement($wrapper, $values, false, null);
			}
		}

		private function getXML($position = 0, $entry_id = NULL) {
			// Cache stuff that can be reused between filter fields and entries
			static $post;
			static $postValues;
			static $entryManager;

			// Remember if $post contains multiple entries or not
			static $expectMultiple;

			$xml = new XMLElement('data');

			// Get post values
			if (empty($postValues) || $position > 0) {
				// TODO: handle post of multiple entries at the same time
				if (empty($post)) {
					$post = General::getPostData();
					// Check is post contains multiple entries or not
					// TODO: make some hidden field required for post, so we can check for sure
					//       if $post['fields'][0]['filterfield'] exists?
					$expectMultiple = (is_array($post['fields']) && is_array($post['fields'][0]) ? true : false);
				}
				if (!empty($post['fields']) && is_array($post['fields'])) {
					$postValues = new XMLElement('post');
					if ($expectMultiple == true) {
						if (!empty($entry_id) && isset($post['id'])) {
							// $entry_id overrides $position
							foreach ($post['id'] as $pos => $id) {
								if ($id == $entry_id) {
									$position = $pos;
									break;
								}
							}
						}
						else if (isset($post['id'][$position]) && is_numeric($post['id'][$position])) {
							$entry_id = $post['id'][$position];
						}
						$postValues->setAttribute('position', $position);
						General::array_to_xml($postValues, $post['fields'][$position], false);
					}
					else if ($position < 1) {
						if (empty($entry_id) && isset($post['id']) && is_numeric($post['id']))
							$entry_id = $post['id'];
						General::array_to_xml($postValues, $post['fields'], false);
					}
					else {
						// TODO: add error element?
					}
				}
			}
			if (!empty($postValues)) $xml->appendChild($postValues);

			// Get old entry
			$entry = NULL;
			if (empty($entryManager)) {
				if (!class_exists('EntryManager')) {
					include_once(TOOLKIT . '/class.entrymanager.php');
				}
				$entryManager = new EntryManager(Symphony::Engine());
			}
			if (!empty($entry_id)) {
				$entry =& $entryManager->fetch($entry_id);
				$entry = $entry[0];
				if (is_object($entry)) {
					$entry_xml = new XMLElement('old-entry');
					$entry_xml->setAttribute('position', $position);
					$this->appendEntryXML($entry_xml, $entryManager->fieldManager, $entry);
					$xml->appendChild($entry_xml);
				}
				else $entry = NULL;
			}
			else {
				$entry =& $entryManager->create();
				$entry->set('section_id', $this->get('parent_section'));
			}

			// Set new entry data. Code found in event.section.php:
			// https://github.com/symphonycms/symphony-2/blob/29244318e4de294df780513ee027edda767dd75a/symphony/lib/toolkit/events/event.section.php#L99
			if (is_object($entry)) {
				self::$recursion = true;
				if (__ENTRY_FIELD_ERROR__ == $entry->checkPostData(($expectMultiple ? $post['fields'][$position] : $post['fields']), $errors, ($entry->get('id') ? true : false))) {
					// Return early - other fields will mark their errors
					self::$recursion = false;
					return self::__OK__;
				}
				// Third argument (simulate) is set to true - no data will be changed in database
				else if (__ENTRY_OK__ != $entry->setDataFromPost(($expectMultiple ? $post['fields'][$position] : $post['fields']), $errors, true, ($entry->get('id') ? true : false))) {
					// Return early - other fields will mark their errors.
					self::$recursion = false;
					return self::__OK__;
				}
				self::$recursion = false;
				$entry_xml = new XMLElement('entry');
				$entry_xml->setAttribute('position', $position);
				$this->appendEntryXML($entry_xml, $entryManager->fieldManager, $entry);
				$xml->appendChild($entry_xml);
			}

			// Get author
			if (Symphony::Engine()->Author) {
				$author = new XMLElement('author');
				$author->setAttribute('id', Symphony::Engine()->Author->get('id'));
				$author->setAttribute('user_type', Symphony::Engine()->Author->get('user_type'));
				$author->setAttribute('primary', Symphony::Engine()->Author->get('primary'));
				$author->setAttribute('username', Symphony::Engine()->Author->get('username'));
				$author->setAttribute('first_name', Symphony::Engine()->Author->get('first_name'));
				$author->setAttribute('last_name', Symphony::Engine()->Author->get('last_name'));
				$xml->appendChild($author);
			}

			return $xml;
		}

		private function getXPath($entry_id = NULL) {
			if (!empty(self::$xpath)) return self::$xpath;

			// Support posts with multiple entries
			// Whenever self::$xpath is empty, it means we're starting next entry
			static $position;
			if (empty($position) && $position !== 0) $position = 0;
			else $position++;

			$xml = $this->getXML($position, $entry_id);

			$dom = new DOMDocument();
			$dom->strictErrorChecking = false;
			$dom->loadXML($xml->generate(true));

			self::$xpath = new DOMXPath($dom);

			if (version_compare(phpversion(), '5.3', '>=')) {
				self::$xpath->registerPhpFunctions();
			}

			return self::$xpath;
		}

	}

