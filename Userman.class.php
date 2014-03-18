<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * This is the FreePBX Ucp Object, a subset of BMO.
 *
 * Copyright (C) 2013 Schmooze Com, INC
 * Copyright (C) 2013 Andrew Nagy <andrew.nagy@schmoozecom.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package   FreePBX UCP
 * @author    Andrew Nagy <andrew.nagy@schmoozecom.com>
 * @license   AGPL v3
 */

class Userman implements BMO {
    private $registeredFunctions = array();
	private $message = '';
	private $userTable = 'freepbx_users';
	private $userSettingsTable = 'freepbx_users_settings';
	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			include(dirname(__FILE__).'/DB_Helper.class.php');
			$this->db = new Database();
		} else {
			$this->FreePBX = $freepbx;
			$this->db = $freepbx->Database;
		}
	}

    function &create() {
		static $obj;
		if (!isset($obj) || !is_object($obj)) {
			$obj = new Userman();
		}
		return $obj;
    }

	public function install() {

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

	public function setMessage($message,$type='info') {
		$this->message = array(
			'message' => $message,
			'type' => $type
		);
		return true;
	}

	public function doConfigPageInit($display) {
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'deluser') {
			$ret = $this->deleteUserByID($_REQUEST['user']);
			$this->message = array(
				'message' => $ret['message'],
				'type' => $ret['type']
			);
			return true;
            //fw1uz-hqX
		}
		if(isset($_POST['submit'])) {
			$username = !empty($_POST['username']) ? $_POST['username'] : '';
			$password = !empty($_POST['password']) ? $_POST['password'] : '';
            $description = !empty($_POST['description']) ? $_POST['description'] : '';
			$prevUsername = !empty($_POST['prevUsername']) ? $_POST['prevUsername'] : '';
			$assigned = !empty($_POST['assigned']) ? $_POST['assigned'] : array();
            $extraData = array(
                'fname' => isset($_POST['fname']) ? $_POST['fname'] : '',
                'lname' => isset($_POST['lname']) ? $_POST['lname'] : '',
                'title' => isset($_POST['title']) ? $_POST['title'] : '',
                'email' => isset($_POST['email']) ? $_POST['email'] : '',
                'cell' => isset($_POST['cell']) ? $_POST['cell'] : '',
                'work' => isset($_POST['work']) ? $_POST['work'] : '',
                'home' => isset($_POST['home']) ? $_POST['home'] : ''
             );
            $default = !empty($_POST['defaultextension']) ? $_POST['defaultextension'] : 'none';
			if(empty($password)) {
				$this->message = array(
					'message' => _('The Password Can Not Be blank!'),
					'type' => 'danger'
				);
				return false;
			}
			if(!empty($username) && empty($prevUsername)) {
				$ret = $this->addUser($username, $password, $default, $description);
				if($ret['status']) {
					$this->setGlobalSettingByID($ret['id'],'assigned',$assigned,$extraData);
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
			} elseif(!empty($username) && !empty($prevUsername)) {
				$password = ($password != '******') ? $password : null;
				$ret = $this->updateUser($prevUsername, $username, $default, $description, $extraData, $password);
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
				$this->message = array(
					'message' => _('Username Can Not Be Blank'),
					'type' => 'danger'
				);
				return false;
			}
		}
	}

	public function myShowPage() {
		global $module_hook;
		$action = !empty($_REQUEST['action']) ? $_REQUEST['action'] : '';
		$html = '';

		$users = $this->getAllUsers();

		$html .= load_view(dirname(__FILE__).'/views/rnav.php',array("users"=>$users));
		switch($action) {
			case 'showuser':
			case 'adduser':
				if($action == 'showuser' && !empty($_REQUEST['user'])) {
					$user = $this->getUserByID($_REQUEST['user']);
					$assigned = $this->getGlobalSettingByID($_REQUEST['user'],'assigned');
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
				foreach(core_users_list() as $list) {
					$cul[$list[0]] = array(
						"name" => $list[1],
						"vmcontext" => $list[2]
					);
				}
				foreach($cul as $e => $u) {
					$fpbxusers[] = array("ext" => $e, "name" => $u['name'], "selected" => in_array($e,$assigned));
				}

                $iuext = $this->getAllInUseExtensions();
                $dfpbxusers[] = array("ext" => 'none', "name" => 'none', "selected" => false);
                foreach($cul as $e => $u) {
                    if($e != $default && in_array($e,$iuext)) {
                        continue;
                    }
                    $dfpbxusers[] = array("ext" => $e, "name" => $u['name'], "selected" => ($e == $default));
                }
				$html .= load_view(dirname(__FILE__).'/views/users.php',array("dfpbxusers" => $dfpbxusers, "fpbxusers" => $fpbxusers, "hookHtml" => $module_hook->hookHtml, "user" => $user, "message" => $this->message));
			break;
			default:
				$html .= load_view(dirname(__FILE__).'/views/general.php',array());
			break;
		}

		return $html;
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
		$sql = "SELECT * FROM ".$this->userTable." order by id";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		return $sth->fetchAll(PDO::FETCH_ASSOC);
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
		$sql = "SELECT * FROM ".$this->userTable." WHERE default_extension = :extension";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':extension' => $extension));
		$user = $sth->fetch(PDO::FETCH_ASSOC);
		return $user;
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
		$sql = "SELECT * FROM ".$this->userTable." WHERE username = :username";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':username' => $username));
		$user = $sth->fetch(PDO::FETCH_ASSOC);
		return $user;
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
		$sql = "SELECT * FROM ".$this->userTable." WHERE id = :id";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $id));
		$user = $sth->fetch(PDO::FETCH_ASSOC);
		return $user;
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
		$user = $this->getUserByID($id);
		if(!$user) {
			return array("status" => false, "type" => "danger", "message" => _("User Does Not Exist"));
		}
		$sql = "DELETE FROM ".$this->userTable." WHERE `id` = :id";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $id));

		$sql = "DELETE FROM ".$this->userSettingsTable." WHERE `uid` = :uid";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':uid' => $id));
		$this->callHooks('delUser',array("id" => $id));
		return array("status" => true, "type" => "success", "message" => _("User Successfully Deleted"));
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
	public function addUser($username, $password, $default='none', $description='', $extraData=array(), $encrypt = true) {
        global $module_hook;
		if(empty($username) || empty($password)) {
			return array("status" => false, "type" => "danger", "message" => _("Username/Password Can Not Be Blank!"));
		}
		if($this->getUserByUsername($username)) {
			return array("status" => false, "type" => "danger", "message" => _("User Already Exists"));
		}
		$sql = "INSERT INTO ".$this->userTable." (`username`,`password`,`description`,`default_extension`) VALUES (:username,:password,:description,:default_extension)";
		$sth = $this->db->prepare($sql);
		$password = ($encrypt) ? sha1($password) : $password;
		$sth->execute(array(':username' => $username, ':password' => $password, ':description' => $description, ':default_extension' => $default));

        $id = $this->db->lastInsertId();
        $this->updateUserExtraData($id,$extraData);
        $this->callHooks('addUser',array("id" => $id, "username" => $username, "description" => $description, "password" => $password, "encrypted" => $encrypt, "extraData" => $extraData));
		return array("status" => true, "type" => "success", "message" => _("User Successfully Added"), "id" => $id);
	}

	/**
	 * Update a User in User Manager
	 *
	 * This Updates a User in User Manager
	 *
	 * @param string $username The username
	 * @param string $password The user Password
	 * @param string $default The default user extension, there is an integrity constraint here so there can't be duplicates
	 * @param string $description a short description of this account
	 * @param array $extraData A hash of extra data to provide about this account (work, email, telephone, etc)
	 * @param string $password The updated password, if null then password isn't updated
	 * @return array
	 */
	public function updateUser($prevUsername, $username, $default='none', $description='', $extraData=array(), $password=null) {
		$user = $this->getUserByUsername($prevUsername);
		if(!$user || empty($user)) {
			return array("status" => false, "type" => "danger", "message" => _("User Does Not Exist"));
		}
        if(isset($password) && (sha1($password) != $user['password'])) {
            $sql = "UPDATE ".$this->userTable." SET `username` = :username, `password` = :password, `description` = :description, `default_extension` = :default_extension WHERE `username` = :prevusername";
            $sth = $this->db->prepare($sql);
            $sth->execute(array(':username' => $username, ':prevusername' => $prevUsername, ':description' => $description, ':password' => sha1($password), ':default_extension' => $default));
        } elseif(($prevUsername != $username) || ($user['description'] != $description) || $user['default_extension'] != $default) {
            $sql = "UPDATE ".$this->userTable." SET `username` = :username, `description` = :description, `default_extension` = :default_extension WHERE `username` = :prevusername";
            $sth = $this->db->prepare($sql);
            $sth->execute(array(':username' => $username, ':prevusername' => $prevUsername, ':description' => $description, ':default_extension' => $default));
        }
        $message = _("Updated User");

        $this->updateUserExtraData($user['id'],$extraData);

        $this->callHooks('updateUser',array("id" => $user['id'], "prevUsername" => $prevUsername, "username" => $username, "description" => $description, "password" => $password, "extraData" => $extraData));
		return array("status" => true, "type" => "success", "message" => $message, "id" => $user['id']);
	}

	/**
	 * Update User Extra Data
	 *
	 * This updates Extra Data about the user
	 * (fname,lname,title,email,cell,work,home,department)
	 *
	 * @param int $id The User Manager User ID
	 * @param array $data A hash of data to update (see above)
	 */
    public function updateUserExtraData($id,$data=array()) {
        if(empty($data)) {
            return true;
        }
        $sql = "UPDATE ".$this->userTable." SET `fname` = :fname, `lname` = :lname, `title` = :title, `email` = :email, `cell` = :cell, `work` = :work, `home` = :home, `department` = :department WHERE `id` = :uid";
        $sth = $this->db->prepare($sql);
        $fname = isset($data['fname']) ? $data['fname'] : '';
        $lname = isset($data['lname']) ? $data['lname'] : '';
        $title = isset($data['title']) ? $data['title'] : '';
        $email = isset($data['email']) ? $data['email'] : '';
        $cell = isset($data['cell']) ? $data['cell'] : '';
        $home = isset($data['home']) ? $data['home'] : '';
        $work = isset($data['work']) ? $data['work'] : '';
        $department = isset($data['department']) ? $data['department'] : '';
        $sth->execute(array(':fname' => $fname, ':lname' => $lname, ':title' => $title, ':email' => $email, ':cell' => $cell, ':work' => $work, ':home' => $home, ':department' => $department, ':uid' => $id));
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
	 * Get a single setting from a User
	 *
	 * Gets a single Globally Defined Sub Setting
	 *
	 * @param int $uid The ID of the user from User Manager
	 * @param string $setting The keyword that references said setting
	 * @return mixed false if nothing, else array
	 */
	public function getGlobalSettingByID($uid,$setting) {
		$sql = "SELECT a.val, a.type FROM ".$this->userSettingsTable." a, ".$this->userTable." b WHERE b.id = a.uid AND b.id = :id AND a.key = :setting AND a.module = 'global'";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $uid, ':setting' => $setting));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			return ($result['type'] == 'json-arr' && $this->isJson($result['val'])) ? json_decode($result['val'],true) : $result['val'];
		}
		return false;
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
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			$fout = array();
			foreach($result as $res) {
				$fout[$res['key']] = ($result['type'] == 'json-arr' && $this->isJson($result['val'])) ? json_decode($result['val'],true) : $result['val'];
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
	 * @return mixed false if nothing, else array
	 */
	public function getModuleSettingByID($uid,$module,$setting) {
		$sql = "SELECT a.val, a.type FROM ".$this->userSettingsTable." a, ".$this->userTable." b WHERE b.id = :id AND b.id = a.uid AND a.module = :module AND a.key = :setting";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $uid, ':setting' => $setting, ':module' => $module));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if($result) {
			return ($result['type'] == 'json-arr' && $this->isJson($result['val'])) ? json_decode($result['val'],true) : $result['val'];
		}
		return false;
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
		if(is_bool($value)) {
			$value = ($value) ? 1 : 0;
		}
		$type = is_array($value) ? 'json-arr' : null;
		$value = is_array($value) ? json_encode($value) : $value;
		$sql = "REPLACE INTO ".$this->userSettingsTable." (`uid`, `module`, `key`, `val`, `type`) VALUES(:id, :module, :setting, :value, :type)";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':id' => $uid, ':module' => $module, ':setting' => $setting, ':value' => $value, ':type' => $type));
	}

	public function checkCredentials($username, $password_sha1) {
		$sql = "SELECT id, password FROM ".$this->userTable." WHERE username = :username";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':username' => $username));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if(!empty($result) && ($password_sha1 == $result['password'])) {
			return $result['id'];
		}
		return false;
	}

	private function isJson($string) {
		json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE);
	}

	private function getAllInUseExtensions() {
		$sql = 'SELECT default_extension FROM '.$this->userTable;
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$devices = $sth->fetchAll(PDO::FETCH_ASSOC);
		$used = array();
		foreach($devices as $device) {
			if($device['default_extension'] == 'none') {
				continue;
			}
			$used[] = $device['default_extension'];
		}
		return $used;
	}

	private function callHooks($action,$data=null) {
		foreach($this->registeredFunctions[$action] as $function) {
			if(function_exists($function) && !empty($data['id'])) {
				$function($data['id'], $_REQUEST['display'], $data);
			}
		}
	}
}
