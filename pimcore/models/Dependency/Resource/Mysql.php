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
 * @package    Dependency
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */

class Dependency_Resource_Mysql extends Pimcore_Model_Resource_Mysql_Abstract {

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
        $this->validColumns = $this->getValidTableColumns("dependencies");
    }

    /**
     * Loads the relations for the given sourceId and type
     *
     * @param integer $id
     * @param string $type
     * @return void
     */
    public function getBySourceId($id = null, $type = null) {

        if ($id && $type) {
            $this->model->setSourceId($id);
            $this->model->setSourceType($id);
        }

        // requires
        $data = $this->db->fetchAll("SELECT * FROM dependencies WHERE sourceid = '" . $this->model->getSourceId() . "' AND sourcetype = '" . $this->model->getSourceType() . "'");

        if (is_array($data) && count($data) > 0) {
            foreach ($data as $d) {
                $this->model->addRequirement($d["targetid"], $d["targettype"]);
            }
        }

        // required by
        $data = array();
        $data = $this->db->fetchAll("SELECT * FROM dependencies WHERE targetid = '" . $this->model->getSourceId() . "' AND targettype = '" . $this->model->getSourceType() . "'");

        if (is_array($data) && count($data) > 0) {
            foreach ($data as $d) {
                $this->model->requiredBy[] = array(
                    "id" => $d["sourceid"],
                    "type" => $d["sourcetype"]
                );
            }
        }
    }

    /**
     * Clear all relations in the database
     * @param Element_Interface $element
     */
    public function clearAllForElement($element) {
        try {

            $id = $element->getId();
            $type = Element_Service::getElementType($element);

            //schedule for sanity check
            $data = $this->db->fetchAll("SELECT * FROM dependencies WHERE targetid = '" . $id . "' AND targettype = '" . $type . "'");
            if (is_array($data)) {
                foreach ($data as $row) {
                    $sanityCheck = new Element_Sanitycheck();
                    $sanityCheck->setId($row['sourceid']);
                    $sanityCheck->setType($row['sourcetype']);
                    $sanityCheck->save();
                }
            }

            $this->db->delete("dependencies", "sourceid = '" . $id . "' AND sourcetype = '" . $type . "'");
            $this->db->delete("dependencies", "targetid = '" . $id . "' AND targettype = '" . $type . "'");
        }
        catch (Exception $e) {
            Logger::error($e);
        }
    }


    /**
     * Clear all relations in the database for current source id
     *
     * @return void
     */
    public function clear() {

        try {
            $this->db->delete("dependencies", "sourceid = '" . $this->model->getSourceId() . "' AND sourcetype = '" . $this->model->getSourceType() . "'");
        }
        catch (Exception $e) {
            Logger::error($e);
        }
    }

    /**
     * Save to database
     *
     * @return void
     */
    public function save() {

        foreach ($this->model->getRequires() as $r) {

            try {
                if ($r["id"] && $r["type"]) {
                    $this->db->insert("dependencies", array(
                        "sourceid" => $this->model->getSourceId(),
                        "sourcetype" => $this->model->getSourceType(),
                        "targetid" => $r["id"],
                        "targettype" => $r["type"]
                    ));
                }
            }
            catch (Exception $e) {
                Logger::error($e);
            }
        }
    }
}