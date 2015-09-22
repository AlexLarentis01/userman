<?php
// vim: set ai ts=4 sw=4 ft=php:
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2013 Schmooze Com Inc.
//
namespace FreePBX\modules;
class Userman extends \FreePBX_Helpers implements \BMO {
	private $registeredFunctions = array();
	private $message = '';
	private $userTable = 'userman_users';
	private $userSettingsTable = 'userman_users_settings';
	private $groupTable = 'userman_groups';
	private $groupSettingsTable = 'userman_groups_settings';
	private $brand = 'FreePBX';
	private $tokenExpiration = "5 minutes";
	private $auth = null;
	private $auths = array();

	public function __construct($freepbx = null) {
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->brand = \FreePBX::Config()->get("DASHBOARD_FREEPBX_BRAND");

		if(!interface_exists('FreePBX\modules\Userman\Auth\Base')) {
			include(__DIR__."/functions.inc/auth/Base.php");
		}
		if(!class_exists('FreePBX\modules\Userman\Auth\Auth')) {
			include(__DIR__."/functions.inc/auth/Auth.php");
		}

		$this->switchAuth($this->getConfig('auth'));
	}

	/**
	 * Return the active authentication object
	 * @return object The authentication object
	 */
	public function getAuthObject() {
		return $this->auth;
	}

	/**
	 * Search for users
	 * @param  string $query   The query string
	 * @param  array $results Array of results (note that this is pass-by-ref)
	 */
	public function search($query, &$results) {
		if(!ctype_digit($query)) {
			$auth = $this->getConfig('auth');
			$sql = "SELECT * FROM ".$this->userTable." WHERE auth = :auth AND (username LIKE :query or description LIKE :query or fname LIKE :query or lname LIKE :query or displayname LIKE :query or title LIKE :query or company LIKE :query or department LIKE :query or email LIKE :query)";
			$sth = $this->db->prepare($sql);
			$sth->execute(array("auth" => $auth, "query" => "%".$query."%"));
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
			foreach($rows as $entry) {
				$entry['displayname'] = !empty($entry['displayname']) ? $entry['displayname'] : trim($entry['fname'] . " " . $entry['lname']);
				$entry['displayname'] = !empty($entry['displayname']) ? $entry['displayname'] : $entry['username'];
				$results[] = array("text" => $entry['displayname'], "type" => "get", "dest" => "?display=userman&action=showuser&user=".$entry['id']);
			}
		}
	}

	/**
	 * Old create object
	 * Dont use this unless you know what you are doing
	 * Accessibility of Userman should be done through BMO
	 * @return object Userman Object
	 */
	public function create() {
		static $obj;
		if (!isset($obj) || !is_object($obj)) {
			$obj = new \FreePBX\modules\Userman();
		}
		return $obj;
	}

	public function install() {
		global $db;
		//Change login type to usermanager if installed.
		if($this->FreePBX->Config->get('AUTHTYPE') == "database") {
			$this->FreePBX->Config->update('AUTHTYPE','usermanager');
		}

		$sqls = array();
		if (!$db->getAll('SHOW TABLES LIKE "userman_users"') && $db->getAll('SHOW TABLES LIKE "freepbx_users"')) {
		  $sqls[] = "RENAME TABLE freepbx_users TO userman_users";
		}

		if (!$db->getAll('SHOW TABLES LIKE "userman_users_settings"') && $db->getAll('SHOW TABLES LIKE "freepbx_users_settings"')) {
		  $sqls[] = "RENAME TABLE freepbx_users_settings TO userman_users_settings";
		}
		foreach($sqls as $sql) {
			$result = $db->query($sql);
			if (DB::IsError($result)) {
				die_freepbx($result->getDebugInfo());
			}
		}

		if(!empty($sqls)) {
		  try {
		    $sth = FreePBX::Database()->prepare('SELECT * FROM userman_users');
		    $sth->execute();
		  } catch(\Exception $e) {
		    out(_("Database rename not completed"));
		    return false;
		  }
		  try {
		    $sth = FreePBX::Database()->prepare('SELECT * FROM userman_users_settings');
		    $sth->execute();
		  } catch(\Exception $e) {
		    out(_("Database rename not completed"));
		    return false;
		  }
		}

		$sqls = array();
		$sqls[] = "CREATE TABLE IF NOT EXISTS `userman_users` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `auth` varchar(150) DEFAULT 'freepbx',
		  `authid` varchar(255) DEFAULT NULL,
		  `username` varchar(150) DEFAULT NULL,
		  `description` varchar(255) DEFAULT NULL,
		  `password` varchar(255) DEFAULT NULL,
		  `default_extension` varchar(45) NOT NULL DEFAULT 'none',
		  `primary_group` int(11) DEFAULT NULL,
		  `permissions` BLOB,
		  `fname` varchar(100) DEFAULT NULL,
		  `lname` varchar(100) DEFAULT NULL,
		  `displayname` varchar(200) DEFAULT NULL,
		  `title` varchar(100) DEFAULT NULL,
		  `company` varchar(100) DEFAULT NULL,
		  `department` varchar(100) DEFAULT NULL,
		  `email` varchar(100) DEFAULT NULL,
		  `cell` varchar(100) DEFAULT NULL,
		  `work` varchar(100) DEFAULT NULL,
		  `home` varchar(100) DEFAULT NULL,
		  `fax` varchar(100) DEFAULT NULL,
		  PRIMARY KEY (`id`),
		  UNIQUE KEY `username_UNIQUE` (`username`,`auth`)
		)";
		$sqls[] = "CREATE TABLE IF NOT EXISTS `userman_users_settings` (
		  `uid` int(11) NOT NULL,
		  `module` char(65) NOT NULL,
		  `key` char(255) NOT NULL,
		  `val` longblob NOT NULL,
		  `type` char(16) DEFAULT NULL,
		  UNIQUE KEY `index4` (`uid`,`module`,`key`),
		  KEY `index2` (`uid`,`key`),
		  KEY `index6` (`module`,`uid`)
		)";
		$sqls[] = "CREATE TABLE IF NOT EXISTS `userman_groups` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `auth` varchar(150) DEFAULT 'freepbx',
		  `authid` varchar(255) DEFAULT NULL,
		  `groupname` varchar(150) DEFAULT NULL,
		  `description` varchar(255) DEFAULT NULL,
		  `priority` int(11) NOT NULL DEFAULT 5,
		  `users` BLOB,
		  `permissions` BLOB,
		  PRIMARY KEY (`id`),
		  UNIQUE KEY `groupname_UNIQUE` (`groupname`,`auth`)
		)";
		$sqls[] = "CREATE TABLE IF NOT EXISTS `userman_groups_settings` (
		  `gid` int(11) NOT NULL,
		  `module` char(65) NOT NULL,
		  `key` char(255) NOT NULL,
		  `val` longblob NOT NULL,
		  `type` char(16) DEFAULT NULL,
		  UNIQUE KEY `index4` (`gid`,`module`,`key`),
		  KEY `index2` (`gid`,`key`),
		  KEY `index6` (`module`,`gid`)
		)";
		foreach($sqls as $sql) {
			$result = $db->query($sql);
			if (\DB::IsError($result)) {
				die_freepbx($result->getDebugInfo());
			}
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_users` WHERE FIELD = "auth"')) {
			out("Adding default extension column");
		    $sql = "ALTER TABLE `userman_users` ADD COLUMN `auth` varchar(255) DEFAULT 'freepbx' AFTER `id`";
		    $result = $db->query($sql);
		    $sql = "ALTER TABLE `userman_users` ADD COLUMN `authid` varchar(255) DEFAULT NULL AFTER `auth`";
		    $result = $db->query($sql);
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_groups` WHERE FIELD = "auth"')) {
			out("Adding default extension column");
		    $sql = "ALTER TABLE `userman_groups` ADD COLUMN `auth` varchar(255) DEFAULT 'freepbx' AFTER `id`";
		    $result = $db->query($sql);
		    $sql = "ALTER TABLE `userman_groups` ADD COLUMN `authid` varchar(255) DEFAULT NULL AFTER `auth`";
		    $result = $db->query($sql);
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_groups` WHERE FIELD = "priority"')) {
			out("Adding default extension column");
		    $sql = "ALTER TABLE `userman_groups` ADD COLUMN `priority` int(11) NOT NULL DEFAULT 5 AFTER `description`";
		    $result = $db->query($sql);
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_users` WHERE FIELD = "default_extension"')) {
			out("Adding default extension column");
		    $sql = "ALTER TABLE `userman_users` ADD COLUMN `default_extension` VARCHAR(45) NOT NULL DEFAULT 'none' AFTER `password`";
		    $result = $db->query($sql);
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_users` WHERE FIELD = "primary_group"')) {
		  //TODO: need to do migration here as well
			out("Adding groups column");
		    $sql = "ALTER TABLE `userman_users` ADD COLUMN `primary_group` varchar(10) DEFAULT NULL AFTER `default_extension`";
		    $result = $db->query($sql);
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_users` WHERE FIELD = "displayname"')) {
		    out("Adding additional field displayname");
		    $sql = "ALTER TABLE `userman_users` ADD COLUMN `displayname` VARCHAR(200) NULL DEFAULT NULL AFTER `lname`";
		    $result = $db->query($sql);
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_users` WHERE FIELD = "fname"')) {
		    out("Adding additional fields");
		    $sql = "ALTER TABLE `userman_users` ADD COLUMN `fname` VARCHAR(100) NULL DEFAULT NULL AFTER `default_extension`, ADD COLUMN `lname` VARCHAR(100) NULL DEFAULT NULL AFTER `fname`, ADD COLUMN `title` VARCHAR(100) NULL DEFAULT NULL AFTER `lname`, ADD COLUMN `department` VARCHAR(100) NULL DEFAULT NULL AFTER `title`, ADD COLUMN `email` VARCHAR(100) NULL DEFAULT NULL AFTER `department`, ADD COLUMN `cell` VARCHAR(100) NULL DEFAULT NULL AFTER `email`, ADD COLUMN `work` VARCHAR(100) NULL DEFAULT NULL AFTER `cell`, ADD COLUMN `home` VARCHAR(100) NULL DEFAULT NULL AFTER `work`";
		    $result = $db->query($sql);
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_users` WHERE FIELD = "company"')) {
		  out("Adding additional field company");
		  $sql = "ALTER TABLE `userman_users` ADD COLUMN `company` VARCHAR(100) NULL DEFAULT NULL AFTER `title`";
		  $result = $db->query($sql);
		}

		if (!$db->getAll('SHOW COLUMNS FROM `userman_users` WHERE FIELD = "fax"')) {
		  out("Adding additional field fax");
		  $sql = "ALTER TABLE `userman_users` ADD COLUMN `fax` VARCHAR(100) NULL DEFAULT NULL AFTER `home`";
		  $result = $db->query($sql);
		}

		$sql = 'SHOW COLUMNS FROM userman_users WHERE FIELD = "auth"';
		$sth = $this->FreePBX->Database->prepare($sql);
		$sth->execute();
		$res = $sth->fetch(\PDO::FETCH_ASSOC);
		if($res['Type'] != "varchar(150)") {
		  $sql = "ALTER TABLE `asterisk`.`userman_users`
		  CHANGE COLUMN `auth` `auth` VARCHAR(150) NULL DEFAULT 'freepbx' ,
		  CHANGE COLUMN `username` `username` VARCHAR(150) NULL DEFAULT NULL ,
		  DROP INDEX `username_UNIQUE` ,
		  ADD UNIQUE INDEX `username_UNIQUE` (`username` ASC, `auth` ASC)";
		  $sth = $this->FreePBX->Database->prepare($sql);
		  $sth->execute();
		}

		$sql = 'SHOW COLUMNS FROM userman_groups WHERE FIELD = "auth"';
		$sth = $this->FreePBX->Database->prepare($sql);
		$sth->execute();
		$res = $sth->fetch(\PDO::FETCH_ASSOC);
		if($res['Type'] != "varchar(150)") {
		  $sql = "ALTER TABLE `asterisk`.`userman_groups`
		  CHANGE COLUMN `auth` `auth` VARCHAR(150) NULL DEFAULT 'freepbx' ,
		  CHANGE COLUMN `groupname` `groupname` VARCHAR(150) NULL DEFAULT NULL ,
		  ADD UNIQUE INDEX `groupname_UNIQUE` (`auth` ASC, `groupname` ASC);";
		  $sth = $this->FreePBX->Database->prepare($sql);
		  $sth->execute();
		}

		$set = array();
		$set['value'] = '';
		$set['defaultval'] =& $set['value'];
		$set['readonly'] = 0;
		$set['hidden'] = 0;
		$set['level'] = 0;
		$set['module'] = 'userman';
		$set['category'] = 'User Management Module';
		$set['emptyok'] = 1;
		$set['name'] = 'Email "From:" Address';
		$set['description'] = 'The From: field for emails when using the user management email feature.';
		$set['type'] = CONF_TYPE_TEXT;
		$this->FreePBX->Config->define_conf_setting('AMPUSERMANEMAILFROM',$set,true);

		//Quick check to see if we are previously installed
		//this lets us know if we need to create a default group
		$sql = "SELECT * FROM userman_groups";
		$sth = $this->FreePBX->Database->prepare($sql);
		try {
		  $sth->execute();
		  $grps = $sth->fetchAll();
		} catch(\Exception $e) {
		  $grps = array();
		}

		if (empty($grps)) {
			$sql = "INSERT INTO userman_groups (`groupname`, `description`, `users`) VALUES (?, ?, ?)";
			$sth = $this->FreePBX->Database->prepare($sql);
			$sth->execute(array(_("All Users"),_("This group was created on install and is automatically assigned to new users. This can be disabled in User Manager Settings"),"[]"));
			$id = $this->FreePBX->Database->lastInsertId();
			$config = array(
				"default-groups" => array($id)
			);
			$this->setConfig("authFREEPBXSettings", $config);
			//Default Group Settings
			$this->setModuleSettingByGID($id,'contactmanager','show', true);
			$this->setModuleSettingByGID($id,'contactmanager','groups',array($id));
			$this->setModuleSettingByGID($id,'fax','enabled',true);
			$this->setModuleSettingByGID($id,'fax','attachformat',"pdf");
			$this->setModuleSettingByGID($id,'faxpro','localstore',"true");
			$this->setModuleSettingByGID($id,'restapi','restapi_token_status', true);
			$this->setModuleSettingByGID($id,'restapi','restapi_users',array("self"));
			$this->setModuleSettingByGID($id,'restapi','restapi_modules',array("*"));
			$this->setModuleSettingByGID($id,'restapi','restapi_rate',"1000");
			$this->setModuleSettingByGID($id,'xmpp','enable', true);
			$this->setModuleSettingByGID($id,'ucp|Global','allowLogin',true);
			$this->setModuleSettingByGID($id,'ucp|Global','originate', true);
			$this->setModuleSettingByGID($id,'ucp|Settings','assigned', array("self"));
			$this->setModuleSettingByGID($id,'ucp|Cdr','enable', true);
			$this->setModuleSettingByGID($id,'ucp|Cdr','assigned', array("self"));
			$this->setModuleSettingByGID($id,'ucp|Cdr','download', true);
			$this->setModuleSettingByGID($id,'ucp|Cdr','playback', true);
			$this->setModuleSettingByGID($id,'ucp|Cel','enable', true);
			$this->setModuleSettingByGID($id,'ucp|Cel','assigned', array("self"));
			$this->setModuleSettingByGID($id,'ucp|Cel','download', true);
			$this->setModuleSettingByGID($id,'ucp|Cel','playback', true);
			$this->setModuleSettingByGID($id,'ucp|Presencestate','enabled',true);
			$this->setModuleSettingByGID($id,'ucp|Voicemail','enable', true);
			$this->setModuleSettingByGID($id,'ucp|Voicemail','assigned', array("self"));

			$this->setConfig("autoGroup", $id);
		}
	}

	/**
	 * Get the ID of the automatically created group
	 * @return int The group ID
	 */
	public function getAutoGroup() {
		return $this->getConfig("autoGroup");
	}
	public function uninstall() {

	}
	public function backup(){

	}
	public function restore($backup){

	}
	public function genConfig() {

	}

	public function writeConfig($conf){
	}

	/**
	 * Quick create display
	 * @return array The array of the display
	 */
	public function getQuickCreateDisplay() {
		$groups = $this->getAllGroups();
		$permissions = $this->getAuthAllPermissions();
		$dgroups = $this->auth->getDefaultGroups();
		$usersC = array();  // Initialize the array.
		foreach($this->FreePBX->Core->getAllUsers() as $user) {
			$usersC[] = $user['extension'];
		}
		$userarray['none'] = _("None");
		foreach($this->getAllUsers() as $user) {
			if($user['default_extension'] != 'none' && in_array($user['default_extension'],$usersC)) {
				//continue;
			}
			$userarray[$user['id']] = $user['username'];
		}
		return array(
			1 => array(
				array(
					'html' => load_view(__DIR__.'/views/quickCreate.php',array("users" => $userarray, "dgroups" => $dgroups, "groups" => $groups, "permissions" => $permissions))
				)
			)
		);
	}

	/**
	 * Quick Create hook
	 * @param string $tech      The device tech
	 * @param int $extension The extension number
	 * @param array $data      The associated data
	 */
	public function processQuickCreate($tech, $extension, $data) {
		if(isset($data['um']) && $data['um'] == "yes") {
			$pass = md5(uniqid());
			$ret = $this->addUser($extension, $pass, $extension, _('Autogenerated user on new device creation'), array('email' => $data['email'], 'displayname' => $data['name']));
			if($ret['status']) {
				$this->setGlobalSettingByID($ret['id'],'assigned',array($extension));
				$autoEmail = $this->getGlobalsetting('autoEmail');
				$autoEmail = is_null($autoEmail) ? true : $autoEmail;
				if($autoEmail) {
					$this->sendWelcomeEmail($extension, $pass);
				}
				$permissions = $this->getAuthAllPermissions();
				if($permissions['modifyGroup']) {
					if(!empty($data['um-groups'])) {
						$groups = $this->getAllGroups();
						foreach($groups as $group) {
							if(in_array($group['id'],$data['um-groups']) && !in_array($ret['id'],$group['users'])) {
								$group['users'][] = $ret['id'];
								$this->updateGroup($group['id'],$group['groupname'], $group['groupname'], $group['description'], $group['users']);
							}
						}
					}
				}
			}
		} elseif(isset($data['um-link'])) {
			$ret = $this->getUserByID($data['um-link']);
			if(!empty($ret)) {
				$this->updateUser($ret['id'], $ret['username'], $ret['username'], $extension, $ret['description']);
				$this->setGlobalSettingByID($ret['id'],'assigned',array($extension));
				$autoEmail = $this->getGlobalsetting('autoEmail');
				$autoEmail = is_null($autoEmail) ? true : $autoEmail;
				if($autoEmail) {
					$this->sendWelcomeEmail($extension);
				}
			}
		}
	}

	/**
	 * Set display message in user manager
	 * Used when upating or adding a user
	 * @param [type] $message [description]
	 * @param string $type    [description]
	 */
	public function setMessage($message,$type='info') {
		$this->message = array(
			'message' => $message,
			'type' => $type
		);
		return true;
	}

	/**
	 * Config Page Init
	 * @param string $display The display name of the page
	 */
	public function doConfigPageInit($display) {
		$request = $_REQUEST;
		if(isset($request['action']) && $request['action'] == 'deluser') {
			$ret = $this->deleteUserByID($request['user']);
			$this->message = array(
				'message' => $ret['message'],
				'type' => $ret['type']
			);
			return true;
		}
		if(isset($_POST['submittype'])) {
			switch($_POST['type']) {
				case 'group':
					$groupname = !empty($request['name']) ? $request['name'] : '';
					$description = !empty($request['description']) ? $request['description'] : '';
					$prevGroupname = !empty($request['prevGroupname']) ? $request['prevGroupname'] : '';
					$users = !empty($request['users']) ? $request['users'] : array();
					if($request['group'] == "") {
						$ret = $this->addGroup($groupname, $description, $users);
						if($ret['status']) {
							$this->message = array(
								'message' => $ret['message'],
								'type' => $ret['type']
							);
						} else {
							$this->message = array(
								'message' => $ret['message'],
								'type' => $ret['type']
							);
							return false;
						}
					} else {
						$ret = $this->updateGroup($request['group'],$prevGroupname, $groupname, $description, $users);
						if($ret['status']) {
							$this->message = array(
								'message' => $ret['message'],
								'type' => $ret['type']
							);
						} else {
							$this->message = array(
								'message' => $ret['message'],
								'type' => $ret['type']
							);
							return false;
						}
					}

					$pbx_login = ($_POST['pbx_login'] == "true") ? true : false;
					$this->setGlobalSettingByGID($ret['id'],'pbx_login',$pbx_login);

					$pbx_admin = ($_POST['pbx_admin'] == "true") ? true : false;
					$this->setGlobalSettingByGID($ret['id'],'pbx_admin',$pbx_admin);

					$this->setGlobalSettingByGID($ret['id'],'pbx_low',$_POST['pbx_low']);
					$this->setGlobalSettingByGID($ret['id'],'pbx_high',$_POST['pbx_high']);

					$this->setGlobalSettingByGID($ret['id'],'pbx_modules',(!empty($_POST['pbx_modules']) ? $_POST['pbx_modules'] : array()));
				break;
				case 'user':
					$username = !empty($request['username']) ? $request['username'] : '';
					$password = !empty($request['password']) ? $request['password'] : '';
					$description = !empty($request['description']) ? $request['description'] : '';
					$prevUsername = !empty($request['prevUsername']) ? $request['prevUsername'] : '';
					$assigned = !empty($request['assigned']) ? $request['assigned'] : array();
					$extraData = array(
						'fname' => isset($request['fname']) ? $request['fname'] : null,
						'lname' => isset($request['lname']) ? $request['lname'] : null,
						'title' => isset($request['title']) ? $request['title'] : null,
						'company' => isset($request['company']) ? $request['company'] : null,
						'department' => isset($request['department']) ? $request['department'] : null,
						'email' => isset($request['email']) ? $request['email'] : null,
						'cell' => isset($request['cell']) ? $request['cell'] : null,
						'work' => isset($request['work']) ? $request['work'] : null,
						'home' => isset($request['home']) ? $request['home'] : null,
						'fax' => isset($request['fax']) ? $request['fax'] : null,
						'displayname' => isset($request['displayname']) ? $request['displayname'] : null
					);
					$default = !empty($request['defaultextension']) ? $request['defaultextension'] : 'none';
					if($request['user'] == "") {
						$ret = $this->addUser($username, $password, $default, $description, $extraData);
						if($ret['status']) {
							$this->setGlobalSettingByID($ret['id'],'assigned',$assigned);
							$this->message = array(
								'message' => $ret['message'],
								'type' => $ret['type']
							);
						} else {
							$this->message = array(
								'message' => $ret['message'],
								'type' => $ret['type']
							);
						}
					} else {
						$password = ($password != '******') ? $password : null;
						$ret = $this->updateUser($request['user'], $prevUsername, $username, $default, $description, $extraData, $password);
						if($ret['status']) {
							$this->setGlobalSettingByID($ret['id'],'assigned',$assigned);
							$this->message = array(
								'message' => $ret['message'],
								'type' => $ret['type']
							);
						} else {
							$this->message = array(
								'message' => $ret['message'],
								'type' => $ret['type']
							);
						}
					}
					if(!empty($ret['status'])) {
						if($_POST['pbx_login'] != "inherit") {
							$pbx_login = ($_POST['pbx_login'] == "true") ? true : false;
							$this->setGlobalSettingByID($ret['id'],'pbx_login',$pbx_login);
						} else {
							$this->setGlobalSettingByID($ret['id'],'pbx_login',null);
						}

						if($_POST['pbx_admin'] != "inherit") {
							$pbx_admin = ($_POST['pbx_admin'] == "true") ? true : false;
							$this->setGlobalSettingByID($ret['id'],'pbx_admin',$pbx_admin);
						} else {
							$this->setGlobalSettingByID($ret['id'],'pbx_admin',null);
						}

						$this->setGlobalSettingByID($ret['id'],'pbx_low',$_POST['pbx_low']);
						$this->setGlobalSettingByID($ret['id'],'pbx_high',$_POST['pbx_high']);

						$this->setGlobalSettingByID($ret['id'],'pbx_modules',!empty($_POST['pbx_modules']) ? $_POST['pbx_modules'] : null);
						if(!empty($_POST['groups'])) {
							$groups = $this->getAllGroups();
							foreach($groups as $group) {
								if(in_array($group['id'],$_POST['groups']) && !in_array($ret['id'],$group['users'])) {
									$group['users'][] = $ret['id'];
									$this->updateGroup($group['id'],$group['groupname'], $group['groupname'], $group['description'], $group['users']);
								} elseif(!in_array($group['id'],$_POST['groups']) && in_array($ret['id'],$group['users'])) {
									$group['users'] = array_diff($group['users'], array($ret['id']));
									$this->updateGroup($group['id'],$group['groupname'], $group['groupname'], $group['description'], $group['users']);
								}
							}
						} else {
							$groups = $this->getGroupsByID($ret['id']);
							foreach($groups as $gid) {
								$group = $this->getGroupByGID($gid);
								$group['users'] = array_diff($group['users'], array($ret['id']));
								$this->updateGroup($group['id'],$group['groupname'], $group['groupname'], $group['description'], $group['users']);
							}
						}
						if(isset($_POST['submittype']) && $_POST['submittype'] == "guisend") {
							$data = $this->getUserByID($request['user']);
							$this->sendWelcomeEmail($data['username'], $password);
						}
					}
				break;
				case 'general':
					$this->setGlobalsetting('emailbody',$request['emailbody']);
					$this->setGlobalsetting('emailsubject',$request['emailsubject']);
					$this->setGlobalsetting('autoEmail',($request['auto-email'] == "yes") ? 1 : 0);
					$this->message = array(
						'message' => _('Saved'),
						'type' => 'success'
					);
					if(isset($_POST['sendemailtoall'])) {
						$this->sendWelcomeEmailToAll();
					}
					$auths = array();
					foreach($this->getAuths() as $auth) {
						if($auth == $_POST['authtype']) {
							$class = 'FreePBX\modules\Userman\Auth\\'.$auth;
							$ret = $class::saveConfig($this, $this->FreePBX);
							if($ret !== true) {
								//error
							}
						}
					}
					$this->setConfig('auth', $_POST['authtype']);
					$this->switchAuth($_POST['authtype']);
				break;
			}
		}
	}

	/**
	 * Get All Permissions that the Auth Type allows
	 */
	public function getAuthAllPermissions() {
		return $this->auth->getPermissions();
	}

	/**
	 * Get a Single Permisison that the Auth Type allows
	 * @param [type] $permission [description]
	 */
	public function getAuthPermission($permission) {
		$settings = $this->auth->getPermissions();
		return isset($settings[$permission]) ? $settings[$permission] : null;
	}

	/**
	 * Get the Action Bar (13)
	 * @param string $request The action bar
	 */
	public function getActionBar($request){
		$buttons = array();
		$permissions = $this->auth->getPermissions();
		$request['action'] = !empty($request['action']) ? $request['action'] : '';
		$request['display'] = !empty($request['display']) ? $request['display'] : '';
		switch($request['display']) {
			case 'userman':
				$buttons = array(
					'submitsend' => array(
						'name' => 'submitsend',
						'id' => 'submitsend',
						'value' => _("Submit & Send Email to User"),
						'class' => array('hidden')
					),
					'submit' => array(
						'name' => 'submit',
						'id' => 'submit',
						'value' => _("Submit"),
						'class' => array('hidden')
					),
					'delete' => array(
						'name' => 'delete',
						'id' => 'delete',
						'value' => _("Delete"),
						'class' => array('hidden')
					),
					'reset' => array(
						'name' => 'reset',
						'id' => 'reset',
						'value' => _("Reset"),
						'class' => array('hidden')
					),
				);

				if($request['action'] != 'showuser' && $request['action'] != 'showgroup'){
					unset($buttons['delete']);
				}

				if($request['action'] == 'showuser' && !$permissions['removeUser']) {
					unset($buttons['delete']);
				}

				if($request['action'] == 'showgroup' && !$permissions['removeGroup']) {
					unset($buttons['delete']);
				}
				if(empty($request['action'])){
					unset($buttons['submitsend']);
				}
			}
		return $buttons;
	}

	/**
	 * Page Display
	 */
	public function myShowPage() {
		if(!function_exists('core_users_list')) {
			return _("Module Core is disabled. Please enable it");
		}
		$module_hook = \moduleHook::create();
		$mods = $this->FreePBX->Hooks->processHooks();
		$sections = array();
		foreach($mods as $mod => $contents) {
			if(empty($contents)) {
				continue;
			}

			if(is_array($contents)) {
				foreach($contents as $content) {
					if(!isset($sections[$content['rawname']])) {
						$sections[$content['rawname']] = array(
							"title" => $content['title'],
							"rawname" => $content['rawname'],
							"content" => $content['content']
						);
					} else {
						$sections[$content['rawname']]['content'] .= $content['content'];
					}
				}
			} else {
				if(!isset($sections[$mod])) {
					$sections[$mod] = array(
						"title" => ucfirst(strtolower($mod)),
						"rawname" => $mod,
						"content" => $contents
					);
				} else {
					$sections[$mod]['content'] .= $contents;
				}
			}
		}
		$request = $_REQUEST;
		$action = !empty($request['action']) ? $request['action'] : '';
		$html = '';

		$users = $this->getAllUsers();
		$groups = $this->getAllGroups();
		$permissions = $this->auth->getPermissions();

		switch($action) {
			case 'addgroup':
			case 'showgroup':
				$module_list = $this->getModuleList();
				if($action == "showgroup") {
					$group = $this->getGroupByGID($request['group']);
				} else {
					$group = array();
				}
				$mods = $this->getGlobalSettingByGID($request['group'],'pbx_modules');
				$html .= load_view(
					dirname(__FILE__).'/views/groups.php',
					array(
						"group" => $group,
						"pbx_modules" => empty($group) ? array() : (!empty($mods) ? $mods : array()),
						"pbx_low" => empty($group) ? '' : $this->getGlobalSettingByGID($request['group'],'pbx_low'),
						"pbx_high" => empty($group) ? '' : $this->getGlobalSettingByGID($request['group'],'pbx_high'),
						"pbx_login" => empty($group) ? false : $this->getGlobalSettingByGID($request['group'],'pbx_login'),
						"pbx_admin" => empty($group) ? false : $this->getGlobalSettingByGID($request['group'],'pbx_admin'),
						"brand" => $this->brand,
						"users" => $users,
						"modules" => $module_list,
						"sections" => $sections,
						"message" => $this->message,
						"permissions" => $permissions
					)
				);
			break;
			case 'showuser':
			case 'adduser':
				if($action == 'showuser' && !empty($request['user'])) {
					$user = $this->getUserByID($request['user']);
					$assigned = $this->getGlobalSettingByID($request['user'],'assigned');
					$assigned = !(empty($assigned)) ? $assigned : array();
					$default = $user['default_extension'];
				} else {
					$user = array();
					$assigned = array();
					$default = null;
				}
				$fpbxusers = array();
				$dfpbxusers = array();
				$cul = array();
				foreach($this->FreePBX->Core->listUsers() as $list) {
					$cul[$list[0]] = array(
						"name" => $list[1],
						"vmcontext" => $list[2]
					);
				}
				foreach($cul as $e => $u) {
					$fpbxusers[] = array("ext" => $e, "name" => $u['name'], "selected" => in_array($e,$assigned));
				}

				$module_list = $this->getModuleList();

				$iuext = $this->getAllInUseExtensions();
				$dfpbxusers[] = array("ext" => 'none', "name" => 'none', "selected" => false);
				foreach($cul as $e => $u) {
					if($e != $default && in_array($e,$iuext)) {
						continue;
					}
					$dfpbxusers[] = array("ext" => $e, "name" => $u['name'], "selected" => ($e == $default));
				}
				$ao = $this->auth;
				$html .= load_view(
					dirname(__FILE__).'/views/users.php',
					array(
						"users" => $users,
						"groups" => $groups,
						"dgroups" => $ao->getDefaultGroups(),
						"sections" => $sections,
						"pbx_modules" => empty($request['user']) ? array() : $this->getGlobalSettingByID($request['user'],'pbx_modules'),
						"pbx_low" => empty($request['user']) ? '' : $this->getGlobalSettingByID($request['user'],'pbx_low'),
						"pbx_high" => empty($request['user']) ? '' : $this->getGlobalSettingByID($request['user'],'pbx_high'),
						"pbx_login" => empty($request['user']) ? false : $this->getGlobalSettingByID($request['user'],'pbx_login',true),
						"pbx_admin" => empty($request['user']) ? false : $this->getGlobalSettingByID($request['user'],'pbx_admin',true),
						"modules" => $module_list,
						"brand" => $this->brand,
						"dfpbxusers" => $dfpbxusers,
						"fpbxusers" => $fpbxusers,
						"user" => $user,
						"message" => $this->message,
						"permissions" => $permissions
					)
				);
			break;
			default:
				$auths = array();
				foreach($this->getAuths() as $auth) {
					$class = 'FreePBX\modules\Userman\Auth\\'.$auth;
					$auths[$auth] = $class::getInfo($this, $this->FreePBX);
					$auths[$auth]['html'] = $class::getConfig($this, $this->FreePBX);
				}

				$emailbody = $this->getGlobalsetting('emailbody');
				$emailsubject = $this->getGlobalsetting('emailsubject');
				$autoEmail = $this->getGlobalsetting('autoEmail');
				$autoEmail = is_null($autoEmail) ? true : $autoEmail;
				$html .= load_view(dirname(__FILE__).'/views/welcome.php',array("autoEmail" => $autoEmail, "authtype" => $this->getConfig("auth"), "auths" => $auths, "brand" => $this->brand, "permissions" => $permissions, "groups" => $groups, "users" => $users, "sections" => $sections,
				 																																"message" => $this->message, "emailbody" => $emailbody, "emailsubject" => $emailsubject));
			break;
		}

		return $html;
	}

	/**
	 * Get List of Menu items from said Modules
	 */
	private function getModuleList() {
		$active_modules = $this->FreePBX->Modules->getActiveModules();
		$module_list = array();
		if(is_array($active_modules)){
			$dis = ($this->FreePBX->Config->get('AMPEXTENSIONS') == 'deviceanduser')?_("Add Device"):_("Add Extension");
			$active_modules['au']['items'][] = array('name' => _("Apply Changes Bar"), 'display' => '99');
			$active_modules['au']['items'][] = array('name' => $dis, 'display' => '999');

			foreach($active_modules as $key => $module) {
				//create an array of module sections to display
				if (isset($module['items']) && is_array($module['items'])) {
					foreach($module['items'] as $itemKey => $item) {
						$listKey = (!empty($item['display']) ? $item['display'] : $itemKey);
						if(isset($item['rawname'])) {
							$item['rawname'] = $module['rawname'];
							\modgettext::push_textdomain($module['rawname']);
						}
						$item['name'] = _($item['name']);
						$module_list[ $listKey ] = $item;
						if(isset($item['rawname'])) {
							\modgettext::pop_textdomain();
						}
					}
				}
			}
		}

		// extensions vs device/users ... module_list setting
		if (isset($amp_conf["AMPEXTENSIONS"]) && ($amp_conf["AMPEXTENSIONS"] == "deviceanduser")) {
			unset($module_list["extensions"]);
		} else {
			unset($module_list["devices"]);
			unset($module_list["users"]);
		}
		unset($module_list['ampusers']);
		return $module_list;
	}

	/**
	 * Ajax Request
	 * @param string $req     The request type
	 * @param string $setting Settings to return back
	 */
	public function ajaxRequest($req, $setting){
		switch($req){
			case "getuserfields":
			case "updatePassword":
			case "delete":
			case "email":
				return true;
			break;
			default:
				return false;
			break;
		}
	}

	/**
	 * Handle AJAX
	 */
	public function ajaxHandler(){
		$request = $_REQUEST;
		switch($request['command']){
			case "email":
				foreach($_REQUEST['extensions'] as $ext){
					$user = $this->getUserbyID($ext);
					if(!empty($user)) {
						$this->sendWelcomeEmail($user['username']);
						return array('status' => true);
					}
					return array('status' => false, "message" => _("Invalid User"));
				}
			break;
			case "getuserfields":
				if(empty($request['id'])){
					print json_encode(_("Error: No id provided"));
				}else{
					$user = $this->getUserByID($request['id']);
					return $user;
				}
			break;
			case "updatePassword":
				$uid = $request['id'];
				$newpass = $request['newpass'];
				$extra = array();
				$user = $this->getUserByID($uid);
				return $this->updateUser($uid, $user['username'], $user['username'], $user['default_extension'], $user['description'], $extra, $newpass);
			break;
			case 'delete':
				switch ($_REQUEST['type']) {
					case 'groups':
						$ret = array();
						foreach($_REQUEST['extensions'] as $ext){
							$ret[$ext] = $this->deleteGroupByGID($ext);
						}
						return array('status' => true, 'message' => $ret);
					break;
					case 'users':
						$ret = array();
						foreach($_REQUEST['extensions'] as $ext){
							$ret[$ext] = $this->deleteUserByID($ext);
						}
						return array('status' => true, 'message' => $ret);
					break;
				}
			break;
			default:
				echo json_encode(_("Error: You should never see this"));
			break;
		}
	}

	/**
	 * Registers a hookable call
	 *
	 * This registers a global function to a hook action
	 *
	 * @param string $action Hook action of: addUser,updateUser or delUser
	 * @return bool
	 */
	public function registerHook($action,$function) {
		$this->registeredFunctions[$action][] = $function;
		return true;
	}

	/**
	 * Get All Users
	 *
	 * Get a List of all User Manager users and their data
	 *
	 * @return array
	 */
	public function getAllUsers() {
		return $this->auth->getAllUsers();
	}

	/**
	* Get All Groups
	*
	* Get a List of all User Manager users and their data
	*
	* @return array
	*/
	public function getAllGroups() {
		return $this->auth->getAllGroups();
	}

	/**
	 * Get all Users as contacts
	 *
	 * @return array
	 */
	public function getAllContactInfo() {
		return $this->auth->getAllContactInfo();
	}

	/**
	 * Get additional contact information from other modules that may hook into Userman
	 * @param array $user The User Array
	 */
	public function getExtraContactInfo($user) {
		$mods = $this->FreePBX->Hooks->processHooks($user);
		foreach($mods as $mod) {
			if(!empty($mod) && is_array($mod)) {
				$user = array_merge($user, $mod);
			}
		}
		return $user;
	}

	/**
	 * Get User Information by the Default Extension
	 *
	 * This gets user information from the user which has said extension defined as it's default
	 *
	 * @param string $extension The User (from Device/User Mode) or Extension to which this User is attached
	 * @return bool
	 */
	public function getUserByDefaultExtension($extension) {
		return $this->auth->getUserByDefaultExtension($extension);
	}

	/**
	 * Get User Information by Username
	 *
	 * This gets user information by username
	 *
	 * @param string $username The User Manager Username
	 * @return bool
	 */
	public function getUserByUsername($username) {
		return $this->auth->getUserByUsername($username);
	}

	/**
	* Get User Information by Username
	*
	* This gets user information by username
	*
	* @param string $username The User Manager Username
	* @return bool
	*/
	public function getGroupByUsername($groupname) {
		return $this->auth->getGroupByUsername($groupname);
	}

	/**
	* Get User Information by Email
	*
	* This gets user information by Email
	*
	* @param string $username The User Manager Email Address
	* @return bool
	*/
	public function getUserByEmail($username) {
		return $this->auth->getUserByEmail($username);
	}

	/**
	 * Get User Information by User ID
	 *
	 * This gets user information by User Manager User ID
	 *
	 * @param string $id The ID of the user from User Manager
	 * @return bool
	 */
	public function getUserByID($id) {
		return $this->auth->getUserByID($id);
	}

	/**
	* Get User Information by User ID
	*
	* This gets user information by User Manager User ID
	*
	* @param string $id The ID of the user from User Manager
	* @return bool
	*/
	public function getGroupByGID($gid) {
		return $this->auth->getGroupByGID($gid);
	}

	/**
	 * Get all Groups that this user is a part of
	 * @param int $uid The User ID
	 */
	public function getGroupsByID($uid) {
		return $this->auth->getGroupsByID($uid);
	}

	/**
	 * Get User Information by Username
	 *
	 * This gets user information by username.
	 * !!This should never be called externally outside of User Manager!!
	 *
	 * @param string $id The ID of the user from User Manager
	 * @return array
	 */
	public function deleteUserByID($id) {
		if(!is_numeric($id)) {
			throw new \Exception(_("ID was not numeric"));
		}
		$status = $this->auth->deleteUserByID($id);
		if(!$status['status']) {
			return $status;
		}
		$this->callHooks('delUser',array("id" => $id));
		$this->delUser($id);
		return $status;
	}

	/**
	 * Delete a Group by it's ID
	 * @param int $gid The group ID
	 */
	public function deleteGroupByGID($gid) {
		if(!is_numeric($gid)) {
			throw new \Exception(_("GID was not numeric"));
		}
		$status = $this->auth->deleteGroupByGID($gid);
		if(!$status['status']) {
			return $status;
		}
		$this->callHooks('delGroup',array("id" => $gid));
		$this->delGroup($gid);
		return $status;
	}

	/**
	 * Switch the authentication engine
	 * @param  string $auth The authentication engine name, will default to freepbx
	 */
	private function switchAuth($auth = 'freepbx') {
		$this->getAuths();
		if(!empty($auth)) {
			$class = 'FreePBX\modules\Userman\Auth\\'.$auth;
			try {
				$this->auth = new $class($this, $this->FreePBX);
			} catch (\Exception $e) {
				//there was an error. Report it but set everything back
				dbug($e->getMessage());
				$this->setConfig('auth', 'freepbx');
				$this->auth = new Userman\Auth\Freepbx($this, $this->FreePBX);
			}
		} else {
			$this->setConfig('auth', 'freepbx');
			$this->auth = new Userman\Auth\Freepbx($this, $this->FreePBX);
		}
	}

	/**
	 * Get all Authenication engines
	 * @return array Array of valid authentication engines
	 */
	private function getAuths() {
		if(!empty($this->auths)) {
			return $this->auths;
		}
		foreach(glob(__DIR__."/functions.inc/auth/modules/*.php") as $auth) {
			$name = basename($auth, ".php");
			if(!class_exists('FreePBX\modules\Userman\Auth\\'.$name)) {
				include(__DIR__."/functions.inc/auth/modules/".$name.".php");
				$this->auths[] = $name;
			}
		}
		return $this->auths;
	}

	/**
	 * This is here so that the processhooks callback has the right function name to hook into
	 *
	 * Note: Should never be called externally, use the above function!!
	 *
	 * @param {int} $id the user id of the deleted user
	 */
	private function delUser($id) {
		$request = $_REQUEST;
		$display = !empty($request['display']) ? $request['display'] : "";
		$this->FreePBX->Hooks->processHooks($id, $display, array("id" => $id));
	}

	/**
	 * This is here so that the processhooks callback has the right function name to hook into
	 *
	 * Note: Should never be called externally, use the above function!!
	 *
	 * @param {int} $gid the group id of the deleted group
	 */
	private function delGroup($gid) {
		$request = $_REQUEST;
		$display = !empty($request['display']) ? $request['display'] : "";
		$this->FreePBX->Hooks->processHooks($gid, $display, array("id" => $gid));
	}

	/**
	 * Add a user to User Manager
	 *
	 * This adds a new user to user manager
	 *
	 * @param string $username The username
	 * @param string $password The user Password
	 * @param string $default The default user extension, there is an integrity constraint here so there can't be duplicates
	 * @param string $description a short description of this account
	 * @param array $extraData A hash of extra data to provide about this account (work, email, telephone, etc)
	 * @param bool $encrypt Whether to encrypt the password or not. If this is false the system will still assume its hashed as sha1, so this is only useful if importing accounts with previous sha1 passwords
	 * @return array
	 */
	public function addUser($username, $password, $default='none', $description=null, $extraData=array(), $encrypt = true) {
		if(empty($username)) {
			throw new \Exception(_("Userman can not be blank"));
		}
		if(empty($password)) {
			throw new \Exception(_("Password can not be blank"));
		}
		$display = !empty($_REQUEST['display']) ? $_REQUEST['display'] : "";
		$status = $this->auth->addUser($username, $password, $default, $description, $extraData, $encrypt);
		if(!$status['status']) {
			return $status;
		}
		$id = $status['id'];
		$this->callHooks('addUser',array("id" => $id, "username" => $username, "description" => $description, "password" => $password, "encrypted" => $encrypt, "extraData" => $extraData));
		$this->FreePBX->Hooks->processHooks($id, $display, array("id" => $id, "username" => $username, "description" => $description, "password" => $password, "encrypted" => $encrypt, "extraData" => $extraData));
		return $status;
	}

	public function addGroup($groupname, $description=null, $users=array()) {
		if(empty($groupname)) {
			throw new \Exception(_("Groupname can not be blank"));
		}
		$display = !empty($_REQUEST['display']) ? $_REQUEST['display'] : "";
		$status = $this->auth->addGroup($groupname, $description=null, $users=array());
		if(!$status['status']) {
			return $status;
		}
		$id = $status['id'];
		$this->FreePBX->Hooks->processHooks($id, $display, array("id" => $id, "groupname" => $groupname, "description" => $description, "users" => $users));
		return $status;
	}

	/**
	 * Update a User in User Manager
	 *
	 * This Updates a User in User Manager
	 *
	 * @param int $uid The User ID
	 * @param string $username The username
	 * @param string $password The user Password
	 * @param string $default The default user extension, there is an integrity constraint here so there can't be duplicates
	 * @param string $description a short description of this account
	 * @param array $extraData A hash of extra data to provide about this account (work, email, telephone, etc)
	 * @param string $password The updated password, if null then password isn't updated
	 * @return array
	 */
	public function updateUser($uid, $prevUsername, $username, $default='none', $description=null, $extraData=array(), $password=null) {
		if(!is_numeric($uid)) {
			throw new \Exception(_("UID was not numeric"));
		}
		if(empty($prevUsername)) {
			throw new \Exception(_("Previous Username can not be blank"));
		}
		/**
		 * Coming from an adaptor that doesnt support username changes
		 */
		if(empty($username)) {
			$username = $prevUsername;
		}
		$display = !empty($_REQUEST['display']) ? $_REQUEST['display'] : "";
		$status = $this->auth->updateUser($uid, $prevUsername, $username, $default, $description, $extraData, $password);
		if(!$status['status']) {
			return $status;
		}
		$id = $status['id'];

		$this->callHooks('updateUser',array("id" => $id, "prevUsername" => $prevUsername, "username" => $username, "description" => $description, "password" => $password, "extraData" => $extraData));
		$this->FreePBX->Hooks->processHooks($id, $display, array("id" => $id, "prevUsername" => $prevUsername, "username" => $username, "description" => $description, "password" => $password, "extraData" => $extraData));
		return $status;
	}

	/**
	 * Update Group
	 * @param string $prevGroupname The group's previous name
	 * @param string $groupname     The Groupname
	 * @param string $description   The group description
	 * @param array  $users         Array of users in this Group
	 */
	public function updateGroup($gid, $prevGroupname, $groupname, $description=null, $users=array()) {
		if(!is_numeric($gid)) {
			throw new \Exception(_("GID was not numeric"));
		}
		if(empty($prevGroupname)) {
			throw new \Exception(_("Previous Groupname can not be blank"));
		}
		/**
		 * Coming from an adaptor that doesnt support groupname changes
		 */
		if(empty($groupname)) {
			$groupname = $prevGroupname;
		}
		$display = !empty($_REQUEST['display']) ? $_REQUEST['display'] : "";
		$status = $this->auth->updateGroup($gid, $prevGroupname, $groupname, $description, $users);
		if(!$status['status']) {
			return $status;
		}
		$id = $status['id'];
		$this->FreePBX->Hooks->processHooks($id, $display, array("id" => $id, "prevGroupname" => $prevGroupname, "groupname" => $groupname, "description" => $description, "users" => $users));
		return $status;
	}

	/**
	 * Check Credentials against username with a password
	 * @param {string} $username      The username
	 * @param {string} $password The sha
	 */
	public function checkCredentials($username, $password) {
		return $this->auth->checkCredentials($username, $password);
	}

	/**
	 * Get the assigned devices (Extensions or ﻿(device/user mode) Users) for this User
	 *
	 * Get the assigned devices (Extensions or ﻿(device/user mode) Users) for this User as a Hashed Array
	 *
	 * @param int $id The ID of the user from User Manager
	 * @return array
	 */
	public function getAssignedDevices($id) {
		return $this->getGlobalSettingByID($id,'assigned');
	}

	/**
	 * Set the assigned devices (Extensions or ﻿(device/user mode) Users) for this User
	 *
	 * Set the assigned devices (Extensions or ﻿(device/user mode) Users) for this User as a Hashed Array
	 *
	 * @param int $id The ID of the user from User Manager
	 * @param array $devices The devices to add to this user as an array
	 * @return array
	 */
	public function setAssignedDevices($id,$devices=array()) {
		return $this->setGlobalSettingByID($id,'assigned',$devices);
	}

	/**
	 * Get Globally Defined Sub Settings
	 *
	 * Gets all Globally Defined Sub Settings
	 *
	 * @param int $uid The ID of the user from User Manager
	 * @return mixed false if nothing, else array
	 */
	public function getAllGlobalSettingsByID($uid) {
		$sql = "SELECT a.val, a.type, a.key FROM ".$this->userSettingsTable." a, ".$this->userTable." b WHERE b.id = a.uid AND b.id = :id AND a.module = 'global'";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $uid));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			$fout = array();
			foreach($result as $res) {
				$fout[$res['key']] = ($result['type'] == 'json-arr' && $this->isJson($result['type'])) ? json_decode($result['type'],true) : $result;
			}
			return $fout;
		}
		return false;
	}

	/**
	 * Get Globally Defined Sub Settings
	 *
	 * Gets all Globally Defined Sub Settings
	 *
	 * @param int $gid The ID of the group from User Manager
	 * @return mixed false if nothing, else array
	 */
	public function getAllGlobalSettingsByGID($gid) {
		$sql = "SELECT a.val, a.type, a.key FROM ".$this->groupSettingsTable." a, ".$this->groupTable." b WHERE b.id = a.gid AND b.id = :id AND a.module = 'global'";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $gid));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			$fout = array();
			foreach($result as $res) {
				$fout[$res['key']] = ($result['type'] == 'json-arr' && $this->isJson($result['type'])) ? json_decode($result['type'],true) : $result;
			}
			return $fout;
		}
		return false;
	}

	/**
	 * Get a single setting from a User
	 *
	 * Gets a single Globally Defined Sub Setting
	 *
	 * @param int $uid The ID of the user from User Manager
	 * @param string $setting The keyword that references said setting
	 * @param bool $null If true return null if the setting doesn't exist, else return false
	 * @return mixed null if nothing, else mixed
	 */
	public function getGlobalSettingByID($uid,$setting,$null=false) {
		$sql = "SELECT a.val, a.type FROM ".$this->userSettingsTable." a, ".$this->userTable." b WHERE b.id = a.uid AND b.id = :id AND a.key = :setting AND a.module = 'global'";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $uid, ':setting' => $setting));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			return ($result['type'] == 'json-arr' && $this->isJson($result['val'])) ? json_decode($result['val'],true) : $result['val'];
		}
		return ($null) ? null : false;
	}

	/**
	 * Get a single setting from a Group
	 *
	 * Gets a single Globally Defined Sub Setting
	 *
	 * @param int $gid The ID of the group from User Manager
	 * @param string $setting The keyword that references said setting
	 * @param bool $null If true return null if the setting doesn't exist, else return false
	 * @return mixed null if nothing, else mixed
	 */
	public function getGlobalSettingByGID($gid,$setting,$null=false) {
		$sql = "SELECT a.val, a.type FROM ".$this->groupSettingsTable." a, ".$this->groupTable." b WHERE b.id = a.gid AND b.id = :id AND a.key = :setting AND a.module = 'global'";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $gid, ':setting' => $setting));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			return ($result['type'] == 'json-arr' && $this->isJson($result['val'])) ? json_decode($result['val'],true) : $result['val'];
		}
		return ($null) ? null : false;
	}

	/**
	 * Gets a single setting after determining groups
	 * by merging group settings into user settings
	 * where as user settings will override groups
	 *
	 * -A true value always overrides a false
	 * -Arrays are merged
	 * -Blank/Empty Values will always take the group that has a setting
	 *
	 * @param int $uid     The user ID to lookup
	 * @param string $setting The setting to get
	 */
	public function getCombinedGlobalSettingByID($id,$setting, $detailed = false) {
		$groupid = -1;
		$groupname = "user";
		$output = $this->getGlobalSettingByID($id,$setting,true);
		if(is_null($output)) {
			$groups = $this->getGroupsByID($id);
			foreach($groups as $group) {
				$gs = $this->getGlobalSettingByGID($group,$setting,true);
				if(!is_null($gs)) {
					//Find and replace the word "self" with this users extension
					if(is_array($gs) && in_array("self",$gs)) {
						$i = array_search ("self", $gs);
						$user = $this->getUserByID($id);
						if($user['default_extension'] !== "none") {
							$gs[$i] = $user['default_extension'];
						}
					}
					$output = $gs;
					$groupid = $group;
					break;
				}
			}
		}
		if($detailed) {
			$grp = ($groupid >= 0) ? $this->getGroupByGID($groupid) : array('groupname' => 'user');
			return array(
				"val" => $output,
				"group" => $groupid,
				"setting" => $setting,
				"groupname" => $grp['groupname']
			);
		} else {
			return $output;
		}
	}

	public function getCombinedModuleSettingByID($id, $module, $setting, $detailed = false) {
		$groupid = -1;
		$groupname = "user";
		$output = $this->getModuleSettingByID($id,$module,$setting,true);
		if(is_null($output)) {
			$groups = $this->getGroupsByID($id);
			foreach($groups as $group) {
				$gs = $this->getModuleSettingByGID($group,$module,$setting,true);
				if(!is_null($gs)) {
					//Find and replace the word "self" with this users extension
					if(is_array($gs) && in_array("self",$gs)) {
						$i = array_search ("self", $gs);
						$user = $this->getUserByID($id);
						if($user['default_extension'] !== "none") {
							$gs[$i] = $user['default_extension'];
						}
					}
					$output = $gs;
					$groupid = $group;
					break;
				}
			}
		}
		if($detailed) {
			$grp = ($groupid >= 0) ? $this->getGroupByGID($groupid) : array('groupname' => 'user');
			return array(
				"val" => $output,
				"group" => $groupid,
				"setting" => $setting,
				"module" => $module,
				"groupname" => $grp['groupname']
			);
		} else {
			return $output;
		}
	}

	/**
	 * Set Globally Defined Sub Setting
	 *
	 * Sets a Globally Defined Sub Setting
	 *
	 * @param int $uid The ID of the user from User Manager
	 * @param string $setting The keyword that references said setting
	 * @param mixed $value Can be an array, boolean or string or integer
	 * @return mixed false if nothing, else array
	 */
	public function setGlobalSettingByID($uid,$setting,$value) {
		if(is_null($value)) {
			return $this->removeGlobalSettingByID($uid,$setting);
		}
		if(is_bool($value)) {
			$value = ($value) ? 1 : 0;
		}
		$type = is_array($value) ? 'json-arr' : null;
		$value = is_array($value) ? json_encode($value) : $value;
		$sql = "REPLACE INTO ".$this->userSettingsTable." (`uid`, `module`, `key`, `val`, `type`) VALUES(:uid, :module, :setting, :value, :type)";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':uid' => $uid, ':module' => 'global', ':setting' => $setting, ':value' => $value, ':type' => $type));
	}

	/**
	 * Remove a Globally Defined Sub Setting
	 * @param int $uid     The user ID
	 * @param string $setting The setting Name
	 */
	public function removeGlobalSettingByID($uid,$setting) {
		$sql = "DELETE FROM ".$this->userSettingsTable." WHERE `module` = :module AND `uid` = :uid AND `key` = :setting";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':uid' => $uid, ':module' => 'global', ':setting' => $setting));
		return true;
	}

	/**
	 * Set Globally Defined Sub Setting
	 *
	 * Sets a Globally Defined Sub Setting
	 *
	 * @param int $gid The ID of the group from User Manager
	 * @param string $setting The keyword that references said setting
	 * @param mixed $value Can be an array, boolean or string or integer
	 * @return mixed false if nothing, else array
	 */
	public function setGlobalSettingByGID($gid,$setting,$value) {
		if(is_null($value)) {
			return $this->removeGlobalSettingByGID($gid,$setting);
		}
		if(is_bool($value)) {
			$value = ($value) ? 1 : 0;
		}
		$type = is_array($value) ? 'json-arr' : null;
		$value = is_array($value) ? json_encode($value) : $value;
		$sql = "REPLACE INTO ".$this->groupSettingsTable." (`gid`, `module`, `key`, `val`, `type`) VALUES(:gid, :module, :setting, :value, :type)";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':gid' => $gid, ':module' => 'global', ':setting' => $setting, ':value' => $value, ':type' => $type));
	}

	/**
	 * Remove a Globally defined sub setting
	 * @param int $gid     The group ID
	 * @param string $setting The setting Name
	 */
	public function removeGlobalSettingByGID($gid,$setting) {
		$sql = "DELETE FROM ".$this->groupSettingsTable." WHERE `module` = :module AND `gid` = :gid AND `key` = :setting";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':gid' => $gid, ':module' => 'global', ':setting' => $setting));
		return true;
	}

	/**
	 * Get All Defined Sub Settings by Module Name
	 *
	 * Get All Defined Sub Settings by Module Name
	 *
	 * @param int $uid The ID of the user from User Manager
	 * @param string $module The module rawname (this can be anything really, another reference ID)
	 * @return mixed false if nothing, else array
	 */
	public function getAllModuleSettingsByID($uid,$module) {
		$sql = "SELECT a.val, a.type, a.key FROM ".$this->userSettingsTable." a, ".$this->userTable." b WHERE b.id = :id AND b.id = a.uid AND a.module = :module";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $uid, ':module' => $module));
		$result = $sth->fetchAll(\PDO::FETCH_ASSOC);
		if($result) {
			$fout = array();
			foreach($result as $res) {
				$fout[$res['key']] = ($res['type'] == 'json-arr' && $this->isJson($res['val'])) ? json_decode($re['val'],true) : $res['val'];
			}
			return $fout;
		}
		return false;
	}

	/**
	 * Get All Defined Sub Settings by Module Name
	 *
	 * Get All Defined Sub Settings by Module Name
	 *
	 * @param int $gid The GID of the user from User Manager
	 * @param string $module The module rawname (this can be anything really, another reference ID)
	 * @return mixed false if nothing, else array
	 */
	public function getAllModuleSettingsByGID($gid,$module) {
		$sql = "SELECT a.val, a.type, a.key FROM ".$this->groupSettingsTable." a, ".$this->groupTable." b WHERE b.id = :id AND b.id = a.gid AND a.module = :module";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $gid, ':module' => $module));
		$result = $sth->fetchAll(\PDO::FETCH_ASSOC);
		if($result) {
			$fout = array();
			foreach($result as $res) {
				$fout[$res['key']] = ($res['type'] == 'json-arr' && $this->isJson($res['val'])) ? json_decode($res['val'],true) : $res['val'];
			}
			return $fout;
		}
		return false;
	}

	/**
	 * Get a single setting from a User by Module
	 *
	 * Gets a single Module Defined Sub Setting
	 *
	 * @param int $uid The ID of the user from User Manager
	 * @param string $module The module rawname (this can be anything really, another reference ID)
	 * @param string $setting The keyword that references said setting
	 * @param bool $null If true return null if the setting doesn't exist, else return false
	 * @return mixed false if nothing, else array
	 */
	public function getModuleSettingByID($uid,$module,$setting,$null=false) {
		$sql = "SELECT a.val, a.type FROM ".$this->userSettingsTable." a, ".$this->userTable." b WHERE b.id = :id AND b.id = a.uid AND a.module = :module AND a.key = :setting";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $uid, ':setting' => $setting, ':module' => $module));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			return ($result['type'] == 'json-arr' && $this->isJson($result['val'])) ? json_decode($result['val'],true) : $result['val'];
		}
		return ($null) ? null : false;
	}

	/**
	* Get a single setting from a User by Module
	*
	* Gets a single Module Defined Sub Setting
	*
	* @param int $uid The ID of the user from User Manager
	* @param string $module The module rawname (this can be anything really, another reference ID)
	* @param string $setting The keyword that references said setting
	* @param bool $null If true return null if the setting doesn't exist, else return false
	* @return mixed false if nothing, else array
	*/
	public function getModuleSettingByGID($gid,$module,$setting,$null=false) {
		$sql = "SELECT a.val, a.type FROM ".$this->groupSettingsTable." a, ".$this->groupTable." b WHERE b.id = :id AND b.id = a.gid AND a.module = :module AND a.key = :setting";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $gid, ':setting' => $setting, ':module' => $module));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			return ($result['type'] == 'json-arr' && $this->isJson($result['val'])) ? json_decode($result['val'],true) : $result['val'];
		}
		return ($null) ? null : false;
	}

	/**
	 * Set a Module Sub Setting
	 *
	 * Sets a Module Defined Sub Setting
	 *
	 * @param int $uid The ID of the user from User Manager
	 * @param string $module The module rawname (this can be anything really, another reference ID)
	 * @param string $setting The keyword that references said setting
	 * @param mixed $value Can be an array, boolean or string or integer
	 * @return mixed false if nothing, else array
	 */
	public function setModuleSettingByID($uid,$module,$setting,$value) {
		if(is_null($value)) {
			$sql = "DELETE FROM ".$this->userSettingsTable." WHERE uid = :id AND module = :module AND `key` = :setting";
			$sth = $this->db->prepare($sql);
			$sth->execute(array(':id' => $uid, ':module' => $module, ':setting' => $setting));
			return true;
		}
		if(is_bool($value)) {
			$value = ($value) ? 1 : 0;
		}
		$type = is_array($value) ? 'json-arr' : null;
		$value = is_array($value) ? json_encode($value) : $value;
		$sql = "REPLACE INTO ".$this->userSettingsTable." (`uid`, `module`, `key`, `val`, `type`) VALUES(:id, :module, :setting, :value, :type)";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $uid, ':module' => $module, ':setting' => $setting, ':value' => $value, ':type' => $type));
	}

	/**
	 * Set a Module Sub Setting
	 *
	 * Sets a Module Defined Sub Setting
	 *
	 * @param int $uid The ID of the user from User Manager
	 * @param string $module The module rawname (this can be anything really, another reference ID)
	 * @param string $setting The keyword that references said setting
	 * @param mixed $value Can be an array, boolean or string or integer
	 * @return mixed false if nothing, else array
	 */
	public function setModuleSettingByGID($gid,$module,$setting,$value) {
		if(is_null($value)) {
			$sql = "DELETE FROM ".$this->groupSettingsTable." WHERE gid = :id AND module = :module AND `key` = :setting";
			$sth = $this->db->prepare($sql);
			$sth->execute(array(':id' => $gid, ':module' => $module, ':setting' => $setting));
			return true;
		}
		if(is_bool($value)) {
			$value = ($value) ? 1 : 0;
		}
		$type = is_array($value) ? 'json-arr' : null;
		$value = is_array($value) ? json_encode($value) : $value;
		$sql = "REPLACE INTO ".$this->groupSettingsTable." (`gid`, `module`, `key`, `val`, `type`) VALUES(:id, :module, :setting, :value, :type)";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $gid, ':module' => $module, ':setting' => $setting, ':value' => $value, ':type' => $type));
	}

	/**
	 * Get all password reset tokens
	 */
	public function getPasswordResetTokens() {
		$tokens = $this->getGlobalsetting('passresettoken');
		$final = array();
		$time = time();
		if(!empty($tokens)) {
			foreach($tokens as $token => $data) {
				if(!empty($data['time']) &&  $data['valid'] < $time) {
					continue;
				}
				$final[$token] = $data;
			}
		}
		$this->setGlobalsetting('passresettoken',$final);
		return $final;
	}

	/**
	 * Reset all password tokens
	 */
	public function resetAllPasswordTokens() {
		$this->setGlobalsetting('passresettoken',array());
	}

	/**
	 * Generate a password reset token for a user
	 * @param int $id The user ID
	 * @param string $valid How long the token key is valid for in string format eg: "5 minutes"
	 * @param bool $force Whether to forcefully generate a token even if one already exists
	 */
	public function generatePasswordResetToken($id, $valid = null, $force = false) {
		$user = $this->getUserByID($id);
		$time = time();
		$valid = !empty($valid) ? $valid : $this->tokenExpiration;
		if(!empty($user)) {
			$tokens = $this->getPasswordResetTokens();
			if(empty($tokens) || !is_array($tokens)) {
				$tokens = array();
			}
			foreach($tokens as $token => $data) {
				if(($data['id'] == $id) && !empty($token['time']) && $data['valid'] > $time) {
					if(!$force) {
						return false;
					}
				}
			}
			$token = bin2hex(openssl_random_pseudo_bytes(16));
			$tokens[$token] = array("id" => $id, "time" => $time, "valid" => strtotime($valid, $time));
			$this->setGlobalsetting('passresettoken',$tokens);
			return array("token" => $token, "valid" => strtotime($valid, $time));
		}
		return false;
	}

	/**
	 * Validate Password Reset token
	 * @param string $token The token
	 */
	public function validatePasswordResetToken($token) {
		$tokens = $this->getPasswordResetTokens();
		if(empty($tokens) || !is_array($tokens)) {
			return false;
		}
		if(isset($tokens[$token])) {
			$user = $this->getUserByID($tokens[$token]['id']);
			if(!empty($user)) {
				return $user;
			}
		}
		return false;
	}

	/**
	 * Reset password for a user base on token
	 * then invalidates the token
	 * @param string $token       The token
	 * @param string $newpassword The password
	 */
	public function resetPasswordWithToken($token,$newpassword) {
		$user = $this->validatePasswordResetToken($token);
		if(!empty($user)) {
			$tokens = $this->getGlobalsetting('passresettoken');
			unset($tokens[$token]);
			$this->setGlobalsetting('passresettoken',$tokens);
			$this->updateUser($user['id'], $user['username'], $user['username'], $user['default_extension'], $user['description'], array(), $newpassword);
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Set a global User Manager Setting
	 * @param {[type]} $key   [description]
	 * @param {[type]} $value [description]
	 */
	public function setGlobalsetting($key, $value) {
		$settings = $this->getGlobalsettings();
		$settings[$key] = $value;
		$sql = "REPLACE INTO module_xml (`id`, `data`) VALUES('userman_data', ?)";
		$sth = $this->db->prepare($sql);
		return $sth->execute(array(json_encode($settings)));
	}

	/**
	 * Get a global User Manager Setting
	 * @param {[type]} $key [description]
	 */
	public function getGlobalsetting($key) {
		$sql = "SELECT data FROM module_xml WHERE id = 'userman_data'";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		$results = !empty($result['data']) ? json_decode($result['data'], true) : array();
		return isset($results[$key]) ? $results[$key] : null;
	}

	/**
	 * Get all global user manager settings
	 */
	public function getGlobalsettings() {
		$sql = "SELECT data FROM module_xml WHERE id = 'userman_data'";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		return !empty($result['data']) ? json_decode($result['data'], true) : array();
	}

	/**
	 * Pre 12 way to call hooks.
	 * @param string $action Action type
	 * @param mixed $data   Data to send
	 */
	private function callHooks($action,$data=null) {
		$display = !empty($_REQUEST['display']) ? $_REQUEST['display'] : "";
		$ret = array();
		if(isset($this->registeredFunctions[$action])) {
			foreach($this->registeredFunctions[$action] as $function) {
				if(function_exists($function) && !empty($data['id'])) {
					$ret[$function] = $function($data['id'], $display, $data);
				}
			}
		}
		return $ret;
	}

	/**
	 * Migrate/Update Voicemail users to User Manager
	 * @param string $context The voicemail context to reference
	 */
	public function migrateVoicemailUsers($context = "default") {
		echo "Starting to migrate Voicemail users\\n";
		$config = $this->FreePBX->LoadConfig();
		$config->loadConfig("voicemail.conf");
		$context = empty($context) ? "default" : $context;
		if($context == "general" || empty($config->ProcessedConfig[$context])) {
			echo "Invalid Context: '".$context."'";
			return false;
		}

		foreach($config->ProcessedConfig[$context] as $exten => $vu) {
			$vars = explode(",",$vu);
			$password = $vars[0];
			$displayname = $vars[1];
			$email = !empty($vars[2]) ? $vars[2] : '';
			$z = $this->getUserByDefaultExtension($exten);
			if(!empty($z)) {
				echo "Voicemail User '".$z['username']."' already has '".$exten."' as it's default extension.";
				if(empty($z['email']) && empty($z['displayname'])) {
					echo "Updating email and displayname from Voicemail.\\n";
					$this->updateUser($z['id'], $z['username'], $z['username'], $z['default_extension'], $z['description'], array('email' => $email, 'displayname' => $displayname));
				} elseif(empty($z['displayname'])) {
					echo "Updating displayname from Voicemail.\\n";
					$this->updateUser($z['id'], $z['username'], $z['username'], $z['default_extension'], $z['description'], array('displayname' => $displayname));
				} elseif(empty($z['email'])) {
					echo "Updating email from Voicemail.\\n";
					$this->updateUser($z['id'], $z['username'], $z['username'], $z['default_extension'], $z['description'], array('email' => $email));
				} else {
					echo "\\n";
				}
				continue;
			}
			$z = $this->getUserByUsername($exten);
			if(!empty($z)) {
				echo "Voicemail User '".$z['username']."' already exists.";
				if(empty($z['email']) && empty($z['displayname'])) {
					echo "Updating email and displayname from Voicemail.\\n";
					$this->updateUser($z['id'], $z['username'], $z['username'], $z['default_extension'], $z['description'], array('email' => $email, 'displayname' => $displayname));
				} elseif(empty($z['displayname'])) {
					echo "Updating displayname from Voicemail.\\n";
					$this->updateUser($z['id'], $z['username'], $z['username'], $z['default_extension'], $z['description'], array('displayname' => $displayname));
				} elseif(empty($z['email'])) {
					echo "Updating email from Voicemail.\\n";
					$this->updateUser($z['id'], $z['username'], $z['username'], $z['default_extension'], $z['description'], array('email' => $email));
				} else {
					echo "\\n";
				}
				continue;
			}
			$user = $this->addUser($exten, $password, $exten, _('Migrated user from voicemail'), array('email' => $email, 'displayname' => $displayname));
			if(!empty($user['id'])) {
				echo "Added ".$exten." with password of ".$password."\\n";
				$this->setAssignedDevices($user['id'], array($exten));
				if(!empty($email)) {
					$this->sendWelcomeEmail($exten, $password);
				}
			} else {
				echo "Could not add ".$exten." because: ".$user['message']."\\n";
			}
		}
		echo "\\nNow run: amportal a ucp enableall\\nTo give all users access to UCP";
	}

	public function sendWelcomeEmailToAll() {
		$users = $this->getAllUsers();
		foreach($users as $user) {
			$this->sendWelcomeEmail($user['username']);
		}
	}

	/**
	 * Sends a welcome email
	 * @param {string} $username The username to send to
	 * @param {string} $password =              null If you want to send the password set it here
	 */
	public function sendWelcomeEmail($username, $password =  null) {
		global $amp_conf;
		$request = $_REQUEST;
		$user = $this->getUserByUsername($username);
		if(empty($user) || empty($user['email'])) {
			return false;
		}

		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https" : "http";
		$user['host'] = $protocol.'://'.$_SERVER["SERVER_NAME"];
		$user['brand'] = $this->brand;

		$usettings = $this->getAuthAllPermissions();
		if(!empty($password)) {
			$user['password'] = $password;
		} elseif(!$usettings['changePassword']) {
			$user['password'] = _("Set by your administrator");
		} else {
			$user['password'] = _("Obfuscated. To reset use the reset link in this email");
		}

		$mods = $this->callHooks('welcome',array('id' => $user['id'], 'brand' => $user['brand'], 'host' => $user['host']));
		$user['services'] = '';
		foreach($mods as $mod) {
			$user['services'] .= $mod . "\n";
		}

		$request['display'] = !empty($request['display']) ? $request['display'] : "";
		$mods = $this->FreePBX->Hooks->processHooks($user['id'], $request['display'], array('id' => $user['id'], 'brand' => $user['brand'], 'host' => $user['host'], 'password' => !empty($password)));
		foreach($mods as $mod => $items) {
			foreach($items as $item) {
				$user['services'] .= $item . "\n";
			}
		}

		$dbemail = $this->getGlobalsetting('emailbody');
		$template = !empty($dbemail) ? $dbemail : file_get_contents(__DIR__.'/views/emails/welcome_text.tpl');
		preg_match_all('/%([\w|\d]*)%/',$template,$matches);

		foreach($matches[1] as $match) {
			$replacement = !empty($user[$match]) ? $user[$match] : '';
			$template = str_replace('%'.$match.'%',$replacement,$template);
		}
		$email_options = array('useragent' => $this->brand, 'protocol' => 'mail');
		$email = new \CI_Email();
		$from = !empty($amp_conf['AMPUSERMANEMAILFROM']) ? $amp_conf['AMPUSERMANEMAILFROM'] : 'freepbx@freepbx.org';

		$email->from($from);
		$email->to($user['email']);
		$dbsubject = $this->getGlobalsetting('emailsubject');
		$subject = !empty($dbsubject) ? $dbsubject : _('Your %brand% Account');
		preg_match_all('/%([\w|\d]*)%/',$subject,$matches);
		foreach($matches[1] as $match) {
			$replacement = !empty($user[$match]) ? $user[$match] : '';
			$subject = str_replace('%'.$match.'%',$replacement,$subject);
		}

		$this->sendEmail($user['id'],$subject,$template);
	}

	/**
	 * Send an email to a user
	 * @param int $id      The user ID
	 * @param string $subject The email subject
	 * @param string $body    The email body
	 */
	public function sendEmail($id,$subject,$body) {
		$user = $this->getUserByID($id);
		if(empty($user) || empty($user['email'])) {
			return false;
		}
		$email_options = array('useragent' => $this->brand, 'protocol' => 'mail');
		$email = new \CI_Email();

		//TODO: Stop gap until sysadmin becomes a full class
		if(!function_exists('sysadmin_get_storage_email') && $this->FreePBX->Modules->checkStatus('sysadmin') && file_exists($this->FreePBX->Config()->get('AMPWEBROOT').'/admin/modules/sysadmin/functions.inc.php')) {
			include $this->FreePBX->Config()->get('AMPWEBROOT').'/admin/modules/sysadmin/functions.inc.php';
		}

		$femail = $this->FreePBX->Config()->get('AMPUSERMANEMAILFROM');
		if(function_exists('sysadmin_get_storage_email')) {
			$emails = sysadmin_get_storage_email();
			if(!empty($emails['fromemail']) && filter_var($emails['fromemail'],FILTER_VALIDATE_EMAIL)) {
				$femail = $emails['fromemail'];
			}
		}

		$from = !empty($femail) ? $femail : get_current_user() . '@' . gethostname();

		$email->from($from);
		$email->to($user['email']);

		$email->subject($subject);
		$email->message($body);
		$email->send();
	}

	/**
	 * Check if a string is JSON
	 * @param string $string The string to check
	 */
	private function isJson($string) {
		json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE);
	}

	/**
	 * Get all extensions that are in user as the "default extension"
	 */
	private function getAllInUseExtensions() {
		$sql = 'SELECT default_extension FROM '.$this->userTable;
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$devices = $sth->fetchAll(\PDO::FETCH_ASSOC);
		$used = array();
		foreach($devices as $device) {
			if($device['default_extension'] == 'none') {
				continue;
			}
			$used[] = $device['default_extension'];
		}
		return $used;
	}

	public function bulkhandlerGetTypes() {
		$final = array();
		if($this->getAuthPermission('addGroup')) {
			$final['usermanusers'] = array(
				'name' => _('User Manager Users'),
				'description' => _('User Manager Users')
			);
		}
		if($this->getAuthPermission('addUser')) {
			$final['usermangroups'] = array(
				'name' => _('User Manager Groups'),
				'description' => _('User Manager Groups')
			);
		}
	}

	/**
	 * Get headers for the bulk handler
	 * @param  string $type The type of bulk handler
	 * @return array       Array of headers
	 */
	public function bulkhandlerGetHeaders($type) {
		switch ($type) {
			case 'usermanusers':
				$headers = array(
					'username' => array(
						'required' => true,
						'identifier' => _('Login Name'),
						'description' => _('Login Name'),
					),
					'password' => array(
						'required' => true,
						'identifier' => _('Password'),
						'description' => _('Password - plaintext'),
					),
					'default_extension' => array(
						'required' => true,
						'identifier' => _('Primary Extension'),
						'description' => _('Primary Linked Extension'),
					),
					'description' => array(
						'identifier' => _('Description'),
						'description' => _('Description'),
					),
					'fname' => array(
						'identifier' => _('First Name'),
						'description' => _('First Name'),
					),
					'lname' => array(
						'identifier' => _('Last Name'),
						'description' => _('Last Name'),
					),
					'displayname' => array(
						'identifier' => _('Display Name'),
						'description' => _('Display Name'),
					),
					'title' => array(
						'identifier' => _('Title'),
						'description' => _('Title'),
					),
					'company' => array(
						'identifier' => _('Company'),
						'description' => _('Company'),
					),
					'department' => array(
						'identifier' => _('Department'),
						'description' => _('Department'),
					),
					'email' => array(
						'identifier' => _('Email Address'),
						'description' => _('Email Address'),
					),
					'cell' => array(
						'identifier' => _('Cell Phone Number'),
						'description' => _('Cell Phone Number'),
					),
					'work' => array(
						'identifier' => _('Work Phone Number'),
						'description' => _('Work Phone Number'),
					),
					'home' => array(
						'identifier' => _('Home Phone Number'),
						'description' => _('Home Phone Number'),
					),
					'fax' => array(
						'identifier' => _('Fax Phone Number'),
						'description' => _('Fax Phone Number'),
					),
				);

				return $headers;
			case 'usermangroups':
				$headers = array(
					'groupname' => array(
						'required' => true,
						'identifier' => _('Group Name'),
						'description' => _('Group Name'),
					),
					'description' => array(
						'identifier' => _('Description'),
						'description' => _('Description'),
					),
					'users' => array(
						'identifier' => _('User List'),
						'description' => _('Comma delimited list of users'),
					),
				);

				return $headers;
		}
	}

	/**
	 * Validate Bulk Handler
	 * @param  string $type    The type of bulk handling
	 * @param  array $rawData Raw data of array
	 * @return array          Full blown status
	 */
	public function bulkhandlerValidate($type, $rawData) {
		$ret = NULL;

		switch ($type) {
		case 'usermanusers':
		case 'usermangroups':
			if (true) {
				$ret = array(
					'status' => true,
				);
			} else {
				$ret = array(
					'status' => false,
					'message' => sprintf(_('%s records failed validation'), count($rawData))
				);
			}
			break;
		}

		return $ret;
	}

	/**
	 * Actually import the users
	 * @param  string $type    The type of import
	 * @param  array $rawData The raw data as an array
	 * @return array          Full blown status
	 */
	public function bulkhandlerImport($type, $rawData) {
		$ret = NULL;

		switch ($type) {
		case 'usermanusers':
			if($this->getAuthPermission('addUser')) {
				foreach ($rawData as $data) {
					if (empty($data['username'])) {
						return array("status" => false, "message" => _("username is required."));
					}
					if (empty($data['password'])) {
						return array("status" => false, "message" => _("password is required."));
					}
					if (empty($data['default_extension'])) {
						return array("status" => false, "message" => _("default_extension is required."));
					}

					$username = $data['username'];
					$password = $data['password'];
					$default_extension = $data['default_extension'];
					$description = !empty($data['description']) ? $data['description'] : null;

					$extraData = array(
						'fname' => isset($data['fname']) ? $data['fname'] : null,
						'lname' => isset($data['lname']) ? $data['lname'] : null,
						'displayname' => isset($data['displayname']) ? $data['displayname'] : null,
						'title' => isset($data['title']) ? $data['title'] : null,
						'company' => isset($data['company']) ? $data['company'] : null,
						'department' => isset($data['department']) ? $data['department'] : null,
						'email' => isset($data['email']) ? $data['email'] : null,
						'cell' => isset($data['cell']) ? $data['cell'] : null,
						'work' => isset($data['work']) ? $data['work'] : null,
						'home' => isset($data['home']) ? $data['home'] : null,
						'fax' => isset($data['fax']) ? $data['fax'] : null,
					);

					$existing = $this->getUserByUsername($username);
					if ($existing) {
						try {
							$status = $this->updateUser($existing['id'], $username, $username, $default_extension, $description, $extraData, $password);
						} catch (\Exception $e) {
							return array("status" => false, "message" => $e->getMessage());
						}
						if (!$status['status']) {
							$ret = array(
								'status' => false,
								'message' => $status['message'],
							);
							return $ret;
						}
					} else {
						try {
							$status = $this->addUser($username, $password, $default_extension, $description, $extraData, true);
						} catch (\Exception $e) {
							return array("status" => false, "message" => $e->getMessage());
						}
						if (!$status['status']) {
							$ret = array(
								'status' => false,
								'message' => $status['message'],
							);
							return $ret;
						}
					}

					break;
				}

				needreload();
				$ret = array(
					'status' => true,
				);
			} else {
				$ret = array(
					'status' => false,
					'message' => _("This authentication driver does not allow importing"),
				);
			}
		break;
		case 'usermangroups':
			if($this->getAuthPermission('addGroup')) {
				foreach ($rawData as $data) {
					if (empty($data['groupname'])) {
						return array("status" => false, "message" => _("groupname is required."));
					}

					$groupname = $data['groupname'];
					$description = !empty($data['description']) ? $data['description'] : null;

					$users = array();
					if (!empty($data['users'])) {
						$usernames = explode(',', $data['users']);
						foreach ($usernames as $username) {
							$user = $this->getUserByUsername($username);
							if ($user) {
								$users[] = $user['id'];
							}
						}
					}

					$existing = $this->getGroupByUsername($groupname);
					if ($existing) {
						try {
							$status = $this->updateGroup($existing['id'], $groupname, $groupname, $description, $users);
						} catch (\Exception $e) {
							return array("status" => false, "message" => $e->getMessage());
						}
						if (!$status['status']) {
							$ret = array(
								'status' => false,
								'message' => $status['message'],
							);
							return $ret;
						}
					} else {
						try {
							$status = $this->addGroup($groupname, $description, $users);
						} catch (\Exception $e) {
							return array("status" => false, "message" => $e->getMessage());
						}
						if (!$status['status']) {
							$ret = array(
								'status' => false,
								'message' => $status['message'],
							);
							return $ret;
						}
					}
				}
			} else {
				$ret = array(
					'status' => false,
					'message' => _("This authentication driver does not allow importing"),
				);
			}
		break;
		}

		return $ret;
	}

	/**
	 * Bulk handler export
	 * @param  string $type The type of bulk handler
	 * @return array       Array of data to be exporting
	 */
	public function bulkhandlerExport($type) {
		$data = NULL;

		switch ($type) {
		case 'usermanusers':
			$users = $this->getAllUsers();
			foreach ($users as $user) {
				$data[$user['id']] = array(
					'username' => $user['username'],
					'description' => $user['description'],
					'default_extension' => $user['default_extension'],
					'fname' => $user['fname'],
					'lname' => $user['lname'],
					'displayname' => $user['displayname'],
					'title' => $user['title'],
					'company' => $user['company'],
					'department' => $user['department'],
					'email' => $user['email'],
					'cell' => $user['cell'],
					'work' => $user['work'],
					'home' => $user['home'],
					'fax' => $user['fax'],
				);
			}

			break;
		case 'usermangroups':
			$users = $this->getAllUsers();

			$groups = $this->getAllGroups();
			foreach ($groups as $group) {
				$gu = array();
				foreach ($group['users'] as $key => $val) {
					if (isset($users[$key])) {
						$gu[] = $users[$key]['username'];
					}
				}

				$data[$group['id']] = array(
					'groupname' => $group['groupname'],
					'description' => $group['description'],
					'users' => implode(',', $gu),
				);
			}

			break;
		}

		return $data;
	}
}
