<?php
// vim: set ai ts=4 sw=4 ft=php:
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2013 Schmooze Com Inc.
//
//
namespace FreePBX\modules\Userman\Auth;

class Voicemail extends Auth {

	public function __construct($userman, $freepbx) {
		parent::__construct($userman, $freepbx);
		$this->FreePBX = $freepbx;
		$this->userman = $userman;
	}

	/**
	* Get information about this authentication driver
	* @param  object $userman The userman object
	* @param  object $freepbx The FreePBX BMO object
	* @return array          Array of information about this driver
	*/
	public static function getInfo($userman, $freepbx) {
		return array(
			"name" => _("Asterisk Voicemail")
		);
	}

	/**
	 * Get the configuration display of the authentication driver
	 * @param  object $userman The userman object
	 * @param  object $freepbx The FreePBX BMO object
	 * @return string          html display data
	 */
	public static function getConfig($userman, $freepbx) {
		$config = $userman->getConfig("authVoicemailSettings");
		$config['context'] = !empty($config['context']) ? $config['context'] : 'default';
		return load_view(dirname(dirname(dirname(__DIR__)))."/views/voicemail.php", array("config" => $config));
	}

	/**
	 * Save the configuration about the authentication driver
	 * @param  object $userman The userman object
	 * @param  object $freepbx The FreePBX BMO object
	 * @return mixed          Return true if valid. Otherwise return error string
	 */
	public static function saveConfig($userman, $freepbx) {
		$config = array(
			"context" => $_REQUEST['voicemail-context']
		);
		$userman->setConfig("authVoicemailSettings", $config);
		$vm = new static($userman, $freepbx);
		try {
			$vm->sync();
		} catch(\Exception $e) {}
		return true;
	}

	public function sync($output=null) {
		$this->output = $output;
		$config = $this->userman->getConfig("authVoicemailSettings");
		$d = $this->FreePBX->Voicemail->getVoicemail(false);
		if(!empty($d[$config['context']])) {
			$this->out("");
			$valid = array();
			foreach($d[$config['context']] as $username => $d) {
				$um = $this->linkUser($username, 'voicemail', $username);
				if($um['status']) {
					$data = array(
						"description" => $d['name'],
						"displayname" => $d['name'],
						"email" => $d['email'],
						"default_extension" => $username
					);
					$this->updateUserData($um['id'], $data);
					if($um['new']) {
						$this->out(sprintf(_("Added Voicemail User %s"),$username));
						$this->addUserHook($um['id'], $username, $d['name'], null, false, $data);
					} else {
						$this->out(sprintf(_("Updated Voicemail User %s"),$username));
						$this->updateUserHook($um['id'], $username, $username, $d['name'], null, $data);
					}
					$valid[] = $username;
				}
			}
			//remove users
			$fusers = $this->getAllUsers();
			foreach($fusers as $user) {
				if(!in_array($user['authid'],$valid)) {
					$this->out(sprintf(_("Removed Voicemail User %s"),$user['authid']));
					$this->deleteUserByID($user['id']);
				}
			}
		} else {
			$this->out("<error>"._("Could not find the voicemail context")."</error>");
		}
	}

	/**
	 * Return an array of permissions for this adaptor
	 */
	public function getPermissions() {
		return array(
			"addGroup" => true,
			"addUser" => false,
			"modifyGroup" => true,
			"modifyUser" => false,
			"modifyGroupAttrs" => true,
			"modifyUserAttrs" => false,
			"removeGroup" => true,
			"removeUser" => false,
			"changePassword" => false
		);
	}

	/**
	 * Get All Users
	 *
	 * Get a List of all User Manager users and their data
	 *
	 * @return array
	 */
	public function getAllUsers() {
		return parent::getAllUsers('voicemail');
	}

	/**
	* Get All Users
	*
	* Get a List of all User Manager users and their data
	*
	* @return array
	*/
	public function getAllGroups() {
		return parent::getAllGroups('voicemail');
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
		return array("status" => false, "type" => "danger", "message" => _("Voicemail is in Read Only Mode. Addition denied"));
	}

	/**
	 * Add a group to User Manager
	 *
	 * This adds a new group to User Manager
	 *
	 * @param string $groupname   The group Name
	 * @param string $description The group description
	 * @param array  $users       users to add to said group (by ID)
	 */
	public function addGroup($groupname, $description=null, $users=array()) {
		$sql = "INSERT INTO ".$this->groupTable." (`auth`,`groupname`,`description`,`users`) VALUES (:auth,:groupname,:description,:users)";
		$sth = $this->db->prepare($sql);
		try {
			$sth->execute(array(':auth' => 'voicemail', ':groupname' => $groupname, ':description' => $description, ':users' => json_encode($users)));
		} catch (\Exception $e) {
			return array("status" => false, "type" => "danger", "message" => $e->getMessage());
		}

		$id = $this->db->lastInsertId();
		$this->addGroupHook($id, $groupname, $description, $users);
		return array("status" => true, "type" => "success", "message" => _("Group Successfully Added"), "id" => $id);
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
	public function updateUser($uid, $prevUsername, $username, $default='none', $description=null, $extraData=array(), $password=null,$nodisplay=false) {
		$this->updateUserHook($uid, $prevUsername, $username, $description, $password, $extraData, $nodisplay);
		return array("status" => true, "type" => "success", "message" => _("User updated"), "id" => $uid);
	}

	/**
	 * Update Group
	 * @param string $prevGroupname The group's previous name
	 * @param string $groupname     The Groupname
	 * @param string $description   The group description
	 * @param array  $users         Array of users in this Group
	 */
	public function updateGroup($gid, $prevGroupname, $groupname, $description=null, $users=array(), $nodisplay=false) {
		$group = $this->getGroupByUsername($prevGroupname);
		if(!$group || empty($group)) {
			return array("status" => false, "type" => "danger", "message" => sprintf(_("Group '%s' Does Not Exist"),$group));
		}
		$sql = "UPDATE ".$this->groupTable." SET `groupname` = :groupname, `description` = :description, `users` = :users WHERE `auth` = :auth AND `id` = :gid";
		$sth = $this->db->prepare($sql);
		try {
			$sth->execute(array(':groupname' => $groupname, ':auth' => 'voicemail', ':gid' => $gid, ':description' => $description, ':users' => json_encode($users)));
		} catch (\Exception $e) {
			return array("status" => false, "type" => "danger", "message" => $e->getMessage());
		}
		$message = _("Updated Group");
		$this->updateGroupHook($gid, $prevGroupname, $groupname, $description, $users, $nodisplay);
		return array("status" => true, "type" => "success", "message" => $message, "id" => $gid);
	}

	/**
	 * Check Credentials against username with a passworded sha
	 * @param {string} $username      The username
	 * @param {string} $password_sha1 The sha
	 */
	public function checkCredentials($username, $password) {
		$config = $this->userman->getConfig("authVoicemailSettings");
		try {
			$d = $this->FreePBX->Voicemail->getVoicemail();
		} catch(\Exception $e) {
			$path = $this->FreePBX->Config->get("AMPWEBROOT");
			$moduledir = $path."/admin/modules/voicemail";
			$modulename = "voicemail";
			$mn = ucfirst($modulename);
			$bmofile = "$moduledir/$mn.class.php";
			if (file_exists($bmofile)) {
				\FreePBX::create()->injectClass($mn, $bmofile);
			}
			$d = $this->FreePBX->Voicemail->getVoicemail();
		}
		if(!empty($d[$config['context']][$username])) {
			if($password == $d[$config['context']][$username]['pwd']) {
				//Injecting breaks how FreePBX protects itself
				//To fix this just force a refresh of modules
				$this->FreePBX->Modules->active_modules = array();
				$user = $this->getUserByUsername($username);
				return !empty($user['id']) ? $user['id'] : false;
			}
		}
		return false;
	}

	/**
	 * Debug messages
	 * @param  string $message The message
	 * @param  boolean $nl      New line or not
	 */
	private function out($message,$nl=true) {
		if(is_object($this->output) && $this->output->isVerbose()) {
			if($nl) {
				$this->output->writeln($message);
			} else {
				$this->output->write($message);
			}
		} elseif(!is_object($this->output)) {
			dbug($message);
		}
	}
}
