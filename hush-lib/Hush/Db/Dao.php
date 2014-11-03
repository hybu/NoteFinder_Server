<?php
/**
 * Hush Framework
 *
 * @category   Hush
 * @package    Hush_Db
 * @author     James.Huang <shagoo@gmail.com>
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 * @version    $Id$
 */

/**
 * @see Hush_Db
 */
require_once 'Hush/Db.php';

/**
 * @see Hush_Db_Exception
 */
require_once 'Hush/Db/Exception.php';

/**
 * @package Hush_Db
 */
class Hush_Db_Dao
{
	/**
	 * @var Hush_Db
	 */
	private $_dbr = null;
	
	/**
	 * @var Hush_Db
	 */
	private $_dbw = null;
	
	/**
	 * @var string
	 */
	public $dbName = null;
	
	/**
	 * @var string
	 */
	public $tableName = null;
	
	/**
	 * @var string
	 */
	public $shardDbName = null;
	
	/**
	 * @var string
	 */
	public $shardTableName = null;
	
	/**
	 * @var string
	 */
	public $primkey = 'id';
	
	/**
	 * @var string
	 */
	public $charset = 'utf8';
	
	/**
	 * @var Hush_Db_Config
	 */
	protected $_config = null;
	
	/**
	 * @var int|string
	 */
	protected $_shardId = 0;
	
	/**
	 * Construct
	 * Init the target db link
	 * 
	 * @param $type 'READ' or 'WRITE'
	 * @return unknown
	 */
	public function __construct ($configClass = null)
	{
		// check config
		if ($configClass != null) {
			if (!($configClass instanceof Hush_Db_Config)) {
				throw new Hush_Db_Exception('Bad DB config class');
			}
		}
		
		// set config
		$this->_config = $configClass;
		
		// implemented in subclass
		$this->__init();
	}
	
	/**
	 * Destruct
	 * Release the db link
	 */
	public function __destruct ()
	{
		if ($this->dbr) {
			$this->dbr->closeConnection();
		}
		if ($this->dbw) {
			$this->dbw->closeConnection();
		}
	}
	
	/**
	 * Do some preparation after construct
	 * Should be implemented by subclass
	 */
	protected function __init () {}
	
	/**
	 * Set database name
	 * 
	 * @param string $dbName
	 */
	protected function _bindDb ($dbName = '', $charset = '') 
	{
		if ($dbName) $this->dbName = $dbName;
		if ($charset) $this->charset = $charset;
	}
	
	/**
	 * Bind table for CRUD method
	 * 
	 * @param string $tableName Binded table name
	 */
	protected function _bindTable ($tableName = '', $primkey = '')
	{
		if ($tableName) $this->tableName = $tableName;
		if ($primkey) $this->primkey = $primkey;
	}
	
	/**
	 * Do sharding database by shardId
	 * 
	 * @param int $shardId
	 */
	private function _doShardDb ($shardId = 0)
	{
		if (!$this->dbName) {
			throw new Hush_Db_Exception('Please bind db first');
		}
		// if using default shardId
		$shardId = $shardId ? $shardId : $this->_shardId;
		// running callback function
		$this->_config->doShardDb($this->dbName, $this->tableName, $shardId);
		// set sharded table name for dao
		$this->shardDbName = $this->_config->getDbName();
	}
	
	/**
	 * Do sharding table by shardId
	 * 
	 * @param int $shardId
	 */
	private function _doShardTable ($shardId = 0)
	{
		if (!$this->tableName) {
			throw new Hush_Db_Exception('Please bind table first');
		}
		// if using default shardId
		$shardId = $shardId ? $shardId : $this->_shardId;
		// running callback function
		$this->_config->doShardTable($this->dbName, $this->tableName, $shardId);
		// set sharded table name for dao
		$this->shardTableName = $this->_config->getTable();
	}
	
	/**
	 * Get specific master db from config file
	 * 
	 * @param int $cid Cluster index number
	 * @param int $sid Server index number
	 * @return bool
	 */
	private function _getMaster ($cid = null, $sid = null)
	{
		// get master db
		$configs = array();
		if (!is_null($cid) && !is_null($sid)) {
			$configs = $this->_config->getDb($this->dbName, $cid, 'master', $sid);
		}
		return $configs;
	}
	
	/**
	 * Get specific slave db from config file
	 * 
	 * @param int $cid Cluster index number
	 * @param int $sid Server index number
	 * @return bool
	 */
	private function _getSlave ($cid = null, $sid = null)
	{
		// get slave db
		$configs = array();
		if (!is_null($cid) && !is_null($sid)) {
			$configs = $this->_config->getDb($this->dbName, $cid, 'slave', $sid);
		}
		return $configs;
	}
	
	/**
	 * Get read db link
	 * 
	 * @param int $cid Cluster index number
	 * @param int $sid Server index number
	 * @return Hush_Db_Dao
	 */
	public function dbr ($cid = 0, $sid = 0)
	{
		$dbr = null;
		// try to get specific db
		$configs = $this->_getSlave($cid, $sid);
		// try to get sharded db
		if (!$configs) {
			// if specific db can not be found, do sharding
			$this->_doShardDb();
			// get specific db server or random slave server
			$configs = $this->_config->getSlaveDb();
		}
		// try to init db
		if ($configs) {
			$configs['name'] = $this->shardDbName ? $this->shardDbName : $this->dbName;
			$dbr = Hush_Db::dbPool($configs, $this->charset);
		}
		// try to shard table
		$this->_doShardTable();
		return $dbr;
	}
	
	/**
	 * Get write db link
	 * 
	 * @param int $cid Cluster index number
	 * @param int $sid Server index number
	 * @return Hush_Db_Dao
	 */
	public function dbw ($cid = 0, $sid = 0)
	{
		$dbw = null;
		// try to get specific db
		$configs = $this->_getMaster($cid, $sid);
		// try to get sharded db
		if (!$configs) {
			// if specific db can not be found, do sharding
			$this->_doShardDb();
			// get specific db server or random master server
			$configs = $this->_config->getMasterDb();
		}
		// try to init db
		if ($configs) {
			$configs['name'] = $this->shardDbName ? $this->shardDbName : $this->dbName;
			$dbr = Hush_Db::dbPool($configs, $this->charset);
		}
		// try to shard table
		$this->_doShardTable();
		return $dbr;
	}
	
	/**
	 * Set sharding Id
	 * 
	 * @param int $shardId
	 * @return Hush_Db_Dao
	 */
	public function shard ($shardId = 0)
	{
		$this->_shardId = $shardId;
		return $this;
	}
	
	/**
	 * Get sharding table name
	 * 
	 * @return string
	 */
	public function table ()
	{
		if (!$this->tableName) {
			throw new Hush_Db_Exception('Please bind table first');
		}
		return $this->shardTableName ? $this->shardTableName : $this->tableName;
	}
	
	/**
	 * Rewrite select method
	 * 
	 * @return mixed
	 */
	public function select ()
	{
		return $this->dbr()->select();
	}
	
	/**
	 * Create data by insert method
	 * 
	 * @param array $data
	 * @return mixed
	 */
	public function create ($data)
	{
		if ($this->dbw()->insert($this->table(), $data)) {
			return $this->dbw()->lastInsertId();
		}
		return false;
	}
	
	/**
	 * Check data exists by primary key id
	 * 
	 * @param mixed $id Primary key value
	 * @param string $pk Primary key name
	 * @return array
	 */
	public function exist ($id, $primkey = '')
	{
		$primkey = $primkey ? $primkey : $this->primkey;
		$sql = $this->dbr()->select()->from($this->table(), '(1)')->where("$primkey = ?", $id);
		return $this->dbr()->fetchOne($sql);
	}
	
	/**
	 * Load data by primary key id
	 * 
	 * @param mixed $id Primary key value
	 * @param string $pk Primary key name
	 * @return array
	 */
	public function read ($id, $primkey = '')
	{
		$primkey = $primkey ? $primkey : $this->primkey;
		$sql = $this->dbr()->select()->from($this->table())->where("$primkey = ?", $id);
		return $this->dbr()->fetchRow($sql);
	}
	
	/**
	 * Update specific data by where expr
	 * 
	 * @param array $data Update data
	 * @param string $where Where sql expr
	 * @return bool
	 */
	public function update ($data, $wheresql = '')
	{
		if (!$wheresql) {
			if (!isset($data[$this->primkey])) {
				throw new Hush_Db_Exception('Can not find primary key in data');
			}
			$wheresql = $this->dbw()->quoteInto("{$this->primkey} = ?", $data[$this->primkey]);
		}
		return $this->dbw()->update($this->table(), $data, $wheresql);
	}
	
	/**
	 * Delete data by primary key id
	 * 
	 * @param mixed $id Primary key value
	 * @param string $pk Primary key name
	 * @return bool
	 */
	public function delete ($id, $primkey = '')
	{
		$primkey = $primkey ? $primkey : $this->primkey;
		$wheresql = $this->dbw()->quoteInto("$primkey = ?", $id);
		return $this->dbw()->delete($this->table(), $wheresql);
	}
	
	/**
	 * Replace specific data by where expr
	 * 
	 * @param array $data Update data
	 * @param string $where Where sql expr
	 * @return bool
	 */
	public function replace ($data)
	{
		$affect_rows = $this->dbw()->replace($this->table(), $data);
		return ($affect_rows !== false) ? true : false;
	}
}