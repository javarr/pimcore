<?php
/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @category   Pimcore
 * @package    Asset
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */

class Asset_Resource_Mysql extends Element_Resource_Mysql {

    /**
     * List of valid columns in database table
     * This is used for automatic matching the objects properties to the database
     *
     * @var array
     */
    protected $validColumns = array();

    /**
     * Get the valid columns from the database
     *
     * @return void
     */
    public function init() {
        $this->validColumns = $this->getValidTableColumns("assets");
    }

    /**
     * Get the data for the object by id from database and assign it to the object (model)
     *
     * @param integer $id
     * @return void
     */
    public function getById($id) {
        $data = $this->db->fetchRow("SELECT * FROM assets WHERE id = ?", $id);
        if ($data["id"] > 0) {
            $this->assignVariablesToModel($data);
        }
        else {
            throw new Exception("Asset with ID " . $id . " doesn't exists");
        }
    }

    /**
     * Get the data for the object by path from database and assign it to the object (model)
     *
     * @param integer $id
     * @return void
     */
    public function getByPath($path) {
        // remove trailing slash if exists
        if (substr($path, -1) == "/" and strlen($path) > 1) {
            $path = substr($path, 0, count($path) - 2);
        }

        $data = $this->db->fetchRow("SELECT * FROM assets WHERE CONCAT(path,`filename`) = '" . $path . "'");

        if ($data["id"]) {
            $this->assignVariablesToModel($data);
        }
        else {
            throw new Exception("asset " . $path . " doesn't exist");
        }
    }

    /**
     * Create a the new object in database, an get the new assigned ID
     *
     * @return void
     */
    public function create() {
        try {


            $this->db->insert("assets", array(
                "path" => $this->model->getPath(),
                "parentId" => $this->model->getParentId()
            ));

            $date = time();
            $this->model->setId($this->db->lastInsertId());
            $this->model->setCreationDate($date);
            $this->model->setModificationDate($date);

        }
        catch (Exception $e) {
            throw $e;
        }

    }

    /**
     * Update data from object to the database
     *
     * @return void
     */
    public function update() {

        try {
            $this->model->setModificationDate(time());

            $asset = get_object_vars($this->model);

            foreach ($asset as $key => $value) {
                if (in_array($key, $this->validColumns)) {

                    if (is_array($value)) {
                        $value = serialize($value);
                    }
                    $data[$key] = $value;
                }
            }

            // first try to insert a new record, this is because of the recyclebin restore
            try {
                $this->db->insert("assets", $data);
            }
            catch (Exception $e) {
                $this->db->update("assets", $data, "id='" . $this->model->getId() . "'");
            }
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Remove the object from database
     *
     * @return void
     */
    public function delete() {
        try {
            $this->db->delete("assets", "id='" . $this->model->getId() . "'");
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    public function updateChildsPaths($oldPath) {
        //get assets to empty their cache
        $assets = $this->db->fetchAll("SELECT id,path FROM assets WHERE path LIKE '" . $oldPath . "%'");

        //update assets child paths
        $this->db->exec("update assets set path = replace(path,'" . $oldPath . "','" . $this->model->getFullPath() . "') where path like '" . $oldPath . "/%';");

        //update assets child permission paths
        $this->db->exec("update assets_permissions set cpath = replace(cpath,'" . $oldPath . "','" . $this->model->getFullPath() . "') where cpath like '" . $oldPath . "/%';");

        //update assets child properties paths
        $this->db->exec("update properties set cpath = replace(cpath,'" . $oldPath . "','" . $this->model->getFullPath() . "') where cpath like '" . $oldPath . "/%';");


        foreach ($assets as $asset) {
            // empty assets cache
            try {
                Pimcore_Model_Cache::clearTag("asset_" . $asset["id"]);
            }
            catch (Exception $e) {
            }
        }

    }

    /**
     * Get the properties for the object from database and assign it
     *
     * @return void
     */
    public function getProperties($onlyInherited = false) {

        $properties = array();

        $pathParts = explode("/", $this->model->getPath() . $this->model->getKey());
        unset($pathParts[0]);
        $tmpPathes = array();
        $pathConditionParts[] = "cpath = '/'";
        foreach ($pathParts as $pathPart) {
            $tmpPathes[] = $pathPart;
            $pathConditionParts[] = "cpath = '/" . implode("/", $tmpPathes) . "'";
        }

        $pathCondition = implode(" OR ", $pathConditionParts);

        $propertiesRaw = $this->db->fetchAll("SELECT * FROM properties WHERE (((" . $pathCondition . ") AND inheritable = 1) OR cid = '" . $this->model->getId() . "')  AND ctype='asset' ORDER BY cpath ASC");

        foreach ($propertiesRaw as $propertyRaw) {
            
            try {
                $property = new Property();
                $property->setType($propertyRaw["type"]);
                $property->setCid($this->model->getId());
                $property->setName($propertyRaw["name"]);
                $property->setCtype("asset");
                $property->setDataFromResource($propertyRaw["data"]);
                $property->setInherited(true);
                if ($propertyRaw["cid"] == $this->model->getId()) {
                    $property->setInherited(false);
                }
                $property->setInheritable(false);
                if ($propertyRaw["inheritable"]) {
                    $property->setInheritable(true);
                }
                
                if($onlyInherited && !$property->getInherited()) {
                    continue;
                }

                $properties[$propertyRaw["name"]] = $property;
            }
            catch (Exception $e) {
                Logger::error(get_class($this) . ": can't add property " . $propertyRaw["name"] . " to asset " . $this->model->getFullPath());
            }
        }
        
        // if only inherited then only return it and dont call the setter in the model
        if($onlyInherited) {
            return $properties;
        }
        
        $this->model->setProperties($properties);

        return $properties;
    }

    /**
     * deletes all properties for the object from database
     *
     * @return void
     */
    public function deleteAllProperties() {
        $this->db->delete("properties", "cid = '" . $this->model->getId() . "' AND ctype = 'asset'");
    }

    /**
     * get versions from database, and assign it to object
     *
     * @return array
     */
    public function getVersions() {
        $versionIds = $this->db->fetchAll("SELECT id FROM versions WHERE cid = '" . $this->model->getId() . "' AND ctype='asset' ORDER BY `id` DESC");

        $versions = array();
        foreach ($versionIds as $versionId) {
            $versions[] = Version::getById($versionId["id"]);
        }

        $this->model->setVersions($versions);

        return $versions;
    }

    /**
     * get recursivly the permissions for the passed user
     *
     * @param User $user
     * @return Asset_Permission
     */
    public function getPermissionsForUser(User $user) {
        $pathParts = explode("/", $this->model->getPath() . $this->model->getFilename());
        unset($pathParts[0]);
        $tmpPathes = array();
        $pathConditionParts[] = "cpath = '/'";
        foreach ($pathParts as $pathPart) {
            $tmpPathes[] = $pathPart;
            $pathConditionParts[] = "cpath = '/" . implode("/", $tmpPathes) . "'";
        }

        $pathCondition = implode(" OR ", $pathConditionParts);
        $permissionRaw = $this->db->fetchRow("SELECT id FROM assets_permissions WHERE (" . $pathCondition . ") AND userId='" . $user->getId() . "' ORDER BY cpath DESC LIMIT 1");

        //path condition for parent asset
        $parentAssetPathParts = array_slice($pathParts, 0, -1);
        $parentAssetPathConditionParts[] = "cpath = '/'";
        foreach ($parentAssetPathParts as $parentAssetPathPart) {
            $parentAssetTmpPaths[] = $parentAssetPathPart;
            $parentAssetPathConditionParts[] = "cpath = '/" . implode("/", $parentAssetTmpPaths) . "'";
        }
        $parentAssetPathCondition = implode(" OR ", $parentAssetPathConditionParts);
        $parentAssetPermissionRaw = $this->db->fetchRow("SELECT id FROM assets_permissions WHERE (" . $parentAssetPathCondition . ") AND userId='" . $user->getId() . "' ORDER BY cpath DESC LIMIT 1");
        $parentAssetPermissions = new Asset_Permissions();
        if ($parentAssetPermissionRaw["id"]) {

            $parentAssetPermissions = Asset_Permissions::getById($parentAssetPermissionRaw["id"]);
        }


        $parentUser = $user->getParent();
        if ($parentUser instanceof User and $parentUser->isAllowed("assets")) {
            $parentPermission = $this->getPermissionsForUser($parentUser);
        } else $parentPermission = null;

        $permission = new Asset_Permissions();

        if ($permissionRaw["id"] and $parentPermission instanceof Asset_Permissions ) {

            //consider user group permissions
            $permission = Asset_Permissions::getById($permissionRaw["id"]);
            $permissionKeys = $permission->getValidPermissionKeys();

            foreach ($permissionKeys as $key) {
                $getter = "get" . ucfirst($key);
                $setter = "set" . ucfirst($key);

                if ((!$permission->getList() and !$parentPermission->getList())  or !$parentAssetPermissions->getList()) {
                    //no list - return false for all
                    $permission->$setter(false);
                } else if ($parentPermission->$getter()) {
                    //if user group allows -> return true, it overrides the user permission!
                    $permission->$setter(true);
                }
            }


        } else if ($permissionRaw["id"]) {
            //use user permissions, no user group to override anything
            $permission = Asset_Permissions::getById($permissionRaw["id"]);

            //check parent asset's list permission and current object's list permission
            if (!$parentAssetPermissions->getList() or !$permission->getList()) {
                $permissionKeys = $permission->getValidPermissionKeys();
                foreach ($permissionKeys as $key) {
                    $setter = "set" . ucfirst($key);
                    $permission->$setter(false);
                }
            }

        } else if ($parentPermission instanceof Asset_Permissions and $parentPermission->getId() > 0) {
            //use user group permissions - no permission found for user at all
            $permission = $parentPermission;
            //check parent asset's list permission and current object's list permission
            if (!$parentAssetPermissions->getList() or !$permission->getList()) {
                $permissionKeys = $permission->getValidPermissionKeys();
                foreach ($permissionKeys as $key) {
                    $setter = "set" . ucfirst($key);
                    $permission->$setter(false);
                }
            }

        } else {
            //neither user group nor user has permissions set -> use default all allowed
            $permission->setUser($user);
            $permission->setUserId($user->getId());
            $permission->setUsername($user->getUsername());
            $permission->setCid($this->model->getId());
            $permission->setCpath($this->model->getFullPath());

        }

        $this->model->setUserPermissions($permission);
        return $permission;
    }


    /**
     * all user permissions for this document
     * @return void
     */

    public function getPermissions() {

        $permissions = array();

        $permissionsRaw = $this->db->fetchAll("SELECT id FROM assets_permissions WHERE cid='" . $this->model->getId() . "' ORDER BY cpath ASC");

        $userIdMappings = array();
        foreach ($permissionsRaw as $permissionRaw) {
            $permissions[] = Asset_Permissions::getById($permissionRaw["id"]);
        }


        $this->model->setPermissions($permissions);

        return $permissions;
    }


    /**
     * @return void
     */
    public function deleteAllPermissions() {
        $this->db->delete("assets_permissions", "cid='" . $this->model->getId() . "'");
    }


    /**
     * @return void
     */
    public function deleteAllTasks() {
        $this->db->delete("schedule_tasks", "cid='" . $this->model->getId() . "' AND ctype='asset'");
    }

    /**
     * @return string retrieves the current full sset path from DB
     */
    public function getCurrentFullPath() {
        try {
            $data = $this->db->fetchRow("SELECT CONCAT(path,filename) as path FROM assets WHERE id = ?", $this->model->getId());
        }
        catch (Exception $e) {
            Logger::error(get_class($this) . ": could not get current asset path from DB");
        }

        return $data['path'];

    }


    /**
     * quick test if there are childs
     *
     * @return boolean
     */
    public function hasChilds() {
        $c = $this->db->fetchRow("SELECT id FROM assets WHERE parentId = '" . $this->model->getId() . "'");

        $state = false;
        if ($c["id"]) {
            $state = true;
        }

        $this->model->hasChilds = $state;

        return $state;
    }

    /**
     * returns the amount of directly childs (not recursivly)
     *
     * @return integer
     */
    public function getChildAmount() {
        $c = $this->db->fetchRow("SELECT COUNT(*) AS count FROM assets WHERE parentId = '" . $this->model->getId() . "'");
        return $c["count"];
    }
    
    
    public function isLocked () {
        
        // check for an locked element below this element
        $belowLocks = $this->db->fetchRow("SELECT id FROM assets WHERE path LIKE '".$this->model->getFullpath()."%' AND locked IS NOT NULL AND locked != '';");
        
        if(is_array($belowLocks) && count($belowLocks) > 0) {
            return true;
        }
        
        // check for an inherited lock
        $pathParts = explode("/", $this->model->getFullPath());
        unset($pathParts[0]);
        $tmpPathes = array();
        $pathConditionParts[] = "CONCAT(path,`filename`) = '/'";
        foreach ($pathParts as $pathPart) {
            $tmpPathes[] = $pathPart;
            $pathConditionParts[] = "CONCAT(path,`filename`) = '/" . implode("/", $tmpPathes) . "'";
        }

        $pathCondition = implode(" OR ", $pathConditionParts);
        $inhertitedLocks = $this->db->fetchAll("SELECT id FROM assets WHERE (" . $pathCondition . ") AND locked = 'propagate';");
        
        if(is_array($inhertitedLocks) && count($inhertitedLocks) > 0) {
            return true;
        }
        
        
        return false;
    }
}  