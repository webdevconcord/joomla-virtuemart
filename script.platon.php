<?php
defined('_JEXEC') or die('Restricted access');

/**
 * VirtueMart script file
 *
 * This file is executed during install/upgrade and uninstall
 *
 * @author Patrick Kohl, Max Milbers
 * @package VirtueMart
 */

defined('DS') or define('DS', DIRECTORY_SEPARATOR);

@ini_set( 'memory_limit', '32M' );
@ini_set( 'max_execution_time', '120' );
// hack to prevent defining these twice in 1.6 installation
if (!defined('_VM_SCRIPT_INCLUDED')) {

	define('_VM_SCRIPT_INCLUDED', true);


	class com_virtuemart_allinoneInstallerScript {

		public function preflight(){
			//$this->vmInstall();
		}

		public function install(){
			//$this->vmInstall();
		}

		public function discover_install(){
			//$this->vmInstall();
		}

		public function postflight () {
			$this->vmInstall();
		}

		public function vmInstall () {

			jimport('joomla.filesystem.file');
			jimport('joomla.installer.installer');

			$this->createIndexFolder(JPATH_ROOT .DS. 'plugins'.DS.'vmpayment');
			$this->path = JInstaller::getInstance()->getPath('extension_administrator');
			$this->installPlugin('Concord', 'plugin','concord', 'vmpayment');

			$task = JRequest::getCmd('task');
			if($task!='updateDatabase'){
				echo "<H3>Installing  Concord Plugin Success.</h3>";
			} else {
				echo "<H3>Updated Virtuemart Plugin tables</h3>";
			}
			return true;
		}

		/**
		 * Installs a vm plugin into the database
		 *
		 */
		private function installPlugin($name, $type, $element, $group){

			$task = JRequest::getCmd('task');

			if($task!='updateDatabase'){
				$data = array();

				if(version_compare(JVERSION,'1.7.0','ge')) {

					// Joomla! 1.7 code here
					$table = JTable::getInstance('extension');
					$data['enabled'] = 1;
					$data['access']  = 1;
					$tableName = '#__extensions';
					$idfield = 'extension_id';
				} elseif(version_compare(JVERSION,'1.6.0','ge')) {

					// Joomla! 1.6 code here
					$table = JTable::getInstance('extension');
					$data['enabled'] = 1;
					$data['access']  = 1;
					$tableName = '#__extensions';
					$idfield = 'extension_id';
				} else {

					// Joomla! 1.5 code here
					$table = JTable::getInstance('plugin');
					$data['published'] = 1;
					$data['access']  = 0;
					$tableName = '#__plugins';
					$idfield = 'id';
				}

				$data['name'] = $name;
				$data['type'] = $type;
				$data['element'] = $element;
				$data['folder'] = $group;

				$data['client_id'] = 0;


				$src= $this->path .DS. 'plugins' .DS. $group .DS.$element;


				$db = JFactory::getDBO();
				$q = 'SELECT '.$idfield.' FROM `'.$tableName.'` WHERE `name` = "'.$name.'" ';
				$db->setQuery($q);
				$count = $db->loadResult();

				//We write only in the table, when it is not installed already
				if(empty($count)){
	// 				$table->load($count);
					if(version_compare(JVERSION,'1.6.0','ge')) {
						$data['manifest_cache'] = json_encode(JApplicationHelper::parseXMLInstallFile($src.DS.$element.'.xml'));
					}


					if(!$table->bind($data)){
						$app = JFactory::getApplication();
						$app -> enqueueMessage('VMInstaller table->bind throws error for '.$name.' '.$type.' '.$element.' '.$group);
					}

					if(!$table->check($data)){
						$app = JFactory::getApplication();
						$app -> enqueueMessage('VMInstaller table->check throws error for '.$name.' '.$type.' '.$element.' '.$group);

					}

					if(!$table->store($data)){
						$app = JFactory::getApplication();
						$app -> enqueueMessage('VMInstaller table->store throws error for '.$name.' '.$type.' '.$element.' '.$group);
					}

					$errors = $table->getErrors();
					foreach($errors as $error){
						$app = JFactory::getApplication();
						$app -> enqueueMessage( get_class( $this ).'::store '.$error);
					}
				}
			}

			if(version_compare(JVERSION,'1.7.0','ge')) {
				// Joomla! 1.7 code here
				$dst= JPATH_ROOT . DS . 'plugins' .DS. $group.DS.$element;

			} elseif(version_compare(JVERSION,'1.6.0','ge')) {
				// Joomla! 1.6 code here
				$dst= JPATH_ROOT . DS . 'plugins' .DS. $group.DS.$element;
			} else {
				// Joomla! 1.5 code here
				$dst= JPATH_ROOT . DS . 'plugins' .DS. $group;
			}

			if($task!='updateDatabase'){
				$this->recurse_copy( $src ,$dst );
			}

			if($group!='search') {
				$this->updatePluginTable($name, $type, $element, $group, $dst);
			} else {
				if(version_compare(JVERSION,'1.6.0','ge')){
					$this->updatePluginTable($name, $type, $element, $group, $dst);
				}
			}


		}


		public function updatePluginTable($name, $type, $element, $group, $dst){

			$app = JFactory::getApplication();

			//Update Tables
			if (!class_exists( 'VmConfig' )) require(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_virtuemart'.DS.'helpers'.DS.'config.php');

			if (class_exists( 'VmConfig' )){
				$pluginfilename = $dst.DS.$element.'.php';
				require ($pluginfilename);

				//plgVmpaymentPaypal
				$pluginClassname = 'plg'.ucfirst($group).ucfirst($element);

				//Let's get the global dispatcher
				$dispatcher = JDispatcher::getInstance();
				$config = array('type'=>$group,'name'=>$group,'params'=>'');
				$plugin = new $pluginClassname($dispatcher,$config);;
				// 				$updateString = $plugin->getVmPluginCreateTableSQL();
  				//if(function_exists($plugin->getTableSQLFields)){
					$_psType = substr($group, 2);

					$tablename = '#__virtuemart_'.$_psType .'_plg_'. $element;
					$db = JFactory::getDBO();
					$query='SHOW TABLES LIKE "%'.str_replace('#__','',$tablename).'"'	;
				 	$db->setQuery($query);
				 	$result = $db->loadResult();
				 	//$app -> enqueueMessage( get_class( $this ).'::  '.$query.' '.$result);
					if ( $result) {
						$SQLfields = $plugin->getTableSQLFields();
						$loggablefields = $plugin->getTableSQLLoggablefields();
						$tablesFields=array_merge($SQLfields,$loggablefields);
						$update[$tablename]= array($tablesFields, array(),array());

						$app -> enqueueMessage( get_class( $this ).':: VirtueMart2 update '.$tablename);

						if(!class_exists('GenericTableUpdater')) require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'tableupdater.php');
						$updater = new GenericTableUpdater();

						$updater->updateMyVmTables($update);
					}
  				//}
// 				} else {

// 					$app = JFactory::getApplication();
// 					$app -> enqueueMessage( get_class( $plugin ).':: VirtueMart2 function getTableSQLFields not found');

// 				}

			} else {
				$app = JFactory::getApplication();
				$app -> enqueueMessage( get_class( $this ).':: VirtueMart2 must be installed, or the tables cant be updated '.$error);

			}

		}

		/**
		 * @author Max Milbers
		 * @param string $tablename
		 * @param string $fields
		 * @param string $command
		 */
		private function alterTable($tablename,$fields,$command='CHANGE'){

			if(empty($this->db)){
				$this->db = JFactory::getDBO();
			}

			$query = 'SHOW COLUMNS FROM `'.$tablename.'` ';
			$this->db->setQuery($query);
			$columns = $this->db->loadResultArray(0);

			foreach($fields as $fieldname => $alterCommand){
				if(in_array($fieldname,$columns)){
					$query = 'ALTER TABLE `'.$tablename.'` '.$command.' COLUMN `'.$fieldname.'` '.$alterCommand;

					$this->db->setQuery($query);
					$this->db->query();
				}
			}


		}

		/**
		 *
		 * @author Max Milbers
		 * @param string $table
		 * @param string $field
		 * @param string $fieldType
		 * @return boolean This gives true back, WHEN it altered the table, you may use this information to decide for extra post actions
		 */
		private function checkAddFieldToTable($table,$field,$fieldType){

			$query = 'SHOW COLUMNS FROM `'.$table.'` ';
			$this->db->setQuery($query);
			$columns = $this->db->loadResultArray(0);

			if(!in_array($field,$columns)){


				$query = 'ALTER TABLE `'.$table.'` ADD '.$field.' '.$fieldType;
				$this->db->setQuery($query);
				if(!$this->db->query()){
					$app = JFactory::getApplication();
					$app->enqueueMessage('Install checkAddFieldToTable '.$this->db->getErrorMsg() );
					return false;
				} else {
					return true;
				}
			}
			return false;
		}

		/**
		 * copy all $src to $dst folder and remove it
		 *
		 * @author Max Milbers
		 * @param String $src path
		 * @param String $dst path
		 * @param String $type modules, plugins, languageBE, languageFE
		 */
		private function recurse_copy($src,$dst ) {

			$dir = opendir($src);
			$this->createIndexFolder($dst);

			if(is_resource($dir)){
				while(false !== ( $file = readdir($dir)) ) {
					if (( $file != '.' ) && ( $file != '..' )) {
						if ( is_dir($src .DS. $file) ) {
							$this->recurse_copy($src .DS. $file,$dst .DS. $file);
						}
						else {
							if(JFile::exists($dst .DS. $file)){
								if(!JFile::delete($dst .DS. $file)){
									$app = JFactory::getApplication();
									$app -> enqueueMessage('Couldnt delete '.$dst .DS. $file);
								}
							}
							if(!JFile::move($src .DS. $file,$dst .DS. $file)){
								$app = JFactory::getApplication();
								$app -> enqueueMessage('Couldnt move '.$src .DS. $file.' to '.$dst .DS. $file);
							}
						}
					}
				}
				closedir($dir);
				if (is_dir($src)) JFolder::delete($src);
			} else {
				$app = JFactory::getApplication();
				$app -> enqueueMessage('Couldnt read dir '.$dir.' source '.$src);
			}

		}


		public function uninstall() {

			return true;
		}

		/**
		 * creates a folder with empty html file
		 *
		 * @author Max Milbers
		 *
		 */
		public function createIndexFolder($path){

			if(JFolder::create($path)) {
				if(!JFile::exists($path .DS. 'index.html')){
					JFile::copy(JPATH_ROOT.DS.'components'.DS.'index.html', $path .DS. 'index.html');
				}
				return true;
			}
			return false;
		}

	}



	// PLZ look in #vminstall.php# to add your plugin and module
	function com_install(){

		if(!version_compare(JVERSION,'1.6.0','ge')) {
			$vmInstall = new com_virtuemart_allinoneInstallerScript();
			$vmInstall->vmInstall();
		}
		return true;
	}

	function com_uninstall(){

		return true;
	}

} //if defined
// pure php no tag
