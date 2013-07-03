<?php
/**
 * @package      com_pfmigrator
 *
 * @author       Tobias Kuhn (eaxs)
 * @copyright    Copyright (C) 2013 Tobias Kuhn. All rights reserved.
 * @license      http://www.gnu.org/licenses/gpl.html GNU/GPL, see LICENSE.txt
 */

defined('_JEXEC') or die();


jimport('joomla.application.component.modellist');
jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');


/**
 * Projects migration model
 *
 */
class PFmigratorModelProjects extends JModelList
{
    protected $log     = array();
    protected $success = true;
    protected $access  = 1;

    public function process($limitstart = 0)
    {
        $config = JFactory::getConfig();

        $this->access = $config->get('access', 1);

        $query = $this->_db->getQuery(true);
        $query->select('*')
              ->from('#__pf_projects_tmp')
              ->order('id ASC');

        $limit = $this->getLimit();

        $this->_db->setQuery($query, $limitstart, $limit);
        $rows = $this->_db->loadObjectList();

        if (empty($rows) || !is_array($rows)) {
            return true;
        }

        $titles = array();

        foreach ($rows AS $row)
        {
            if (!$this->migrate($row)) return false;

            $titles[] = $row->title;
        }

        $projects = implode(', ', $titles);
        $this->log[] = JText::sprintf('COM_PFMIGRATOR_MIGRATE_PROJECTS_SUCCESS', $projects);

        return true;
    }


    public function getTotal()
    {
        $query = $this->_db->getQuery(true);

        $query->select('COUNT(*)')
              ->from('#__pf_projects_tmp');

        $this->_db->setQuery($query);
        $total = (int) $this->_db->loadResult();

        return $total;
    }


    public function getLimit()
    {
        return 10;
    }


    public function getLog()
    {
        return $this->log;
    }


    public function getSuccess()
    {
        return $this->success;
    }


    protected function migrate($row)
    {
        $nd  = $this->_db->getNullDate();
        $obj = new stdClass();

        $obj->id          = $row->id;
        $obj->title       = $row->title;
        $obj->alias       = JApplication::stringURLSafe($row->title);
        $obj->description = $row->content;
        $obj->created_by  = $row->author;
        $obj->state       = 1;
        $obj->access      = $this->access;

        // Set creation date
        if ($row->cdate) {
            $date = new JDate($row->cdate);
            $obj->created    = $date->toSql();
            $obj->start_date = $obj->created;
        }

        // Set end date
        if ($row->edate) {
            $date = new JDate($row->edate);
            $obj->end_date = $date->toSql();
        }

        // Set state
        if ($row->archived) {
            $obj->state = 2;
        }
        elseif (!$row->approved) {
            $obj->state = 0;
        }

        // Set attribs
        $obj->attribs = array();
        $obj->attribs['website'] = $row->website;
        $obj->attribs['email']   = $row->email;

        $obj->attribs = json_encode($obj->attribs);

        // Store base item
        if (!$this->_db->insertObject('#__pf_projects', $obj)) {
            $this->success = false;
            $this->log[] = $this->_db->getError();
            return false;
        }

        // Create asset
        $nulls  = false;
        $parent = $this->getParentAsset();
        $asset  = JTable::getInstance('Asset', 'JTable', array('dbo' => $this->_db));

        $asset->loadByName('com_pfprojects.project.' . $row->id);

        // Check for an error.
        if ($error = $asset->getError()) {
            $this->log[] = $error;
        }
        else {
            if (empty($asset->id)) {
                $asset->setLocation($parent, 'last-child');
            }

            // Prepare the asset to be stored.
            $asset->parent_id = $parent;
            $asset->name      = 'com_pfprojects.project.' . $row->id;
            $asset->title     = $row->title;
            $asset->rules     = '{}';

            if (!$asset->check() || !$asset->store($nulls)) {
                $this->log[] = $asset->getError();
            }
            else {
                $query = $this->_db->getQuery(true);

                $query->update('#__pf_projects')
                      ->set('asset_id = ' . (int) $asset->id)
                      ->where('id = ' . (int) $row->id);

                $this->_db->setQuery($query);

                if (!$this->_db->execute()) {
                    $this->log[] = JText::sprintf('JLIB_DATABASE_ERROR_STORE_FAILED_UPDATE_ASSET_ID', $this->_db->getErrorMsg());
                }
            }
        }

        // Move the logo
        if ($row->logo) {
            $tmp_path = $this->getLogoPath();

            if ($tmp_path) {
                $tmp_logo = null;

                if (JFile::exists($tmp_path . $row->logo)) {
                    $tmp_logo = $tmp_path . $row->logo;
                }
                elseif (JFile::exists($tmp_path . '/' . $row->logo)) {
                    $tmp_logo = $tmp_path . '/' . $row->logo;
                }

                if ($tmp_logo) {
                    $ext  = strtolower(JFile::getExt($row->logo));
                    $dest = JPATH_SITE . '/media/com_projectfork/repo/0/logo/' . $row->id . '.' . $ext;

                    if (!JFile::move($tmp_logo, $dest)) {
                        $this->log[] = JText::sprintf('COM_PFMIGRATOR_MOVE_PROJECT_LOGO_FAILED', $row->title);
                    }
                }
            }
        }

        return true;
    }


    protected function getLogoPath()
    {
        static $path = null;

        if (!is_null($path)) return $path;

        $cfg_path = PFmigratorHelper::getConfig('logo_save_path', 'projects');

        if (empty($cfg_path)) {
            $path = false;
            return $path;
        }

        if (JFolder::exists(JPATH_SITE . $cfg_path)) {
            $path = JPATH_SITE . $cfg_path;
            return $path;
        }
        elseif (JFolder::exists(JPATH_SITE . '/' . $cfg_path)) {
            $path = JPATH_SITE . '/' . $cfg_path;
            return $path;
        }
        else {
            $path = false;
            return $path;
        }
    }


    protected function getParentAsset()
    {
        static $parent = null;

        if (!is_null($parent)) return $parent;

        $query = $this->_db->getQuery(true);

        $query->select('id')
              ->from('#__assets')
              ->where('name = ' . $this->_db->quote('com_pfprojects'));

        $this->_db->setQuery($query);
        $parent = (int) $this->_db->loadResult();

        if (!$parent) $parent = 1;

        return $parent;
    }
}
