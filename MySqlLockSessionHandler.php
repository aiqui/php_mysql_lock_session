<?php

/**
 * Class MySqlLockSessionHandler
 * A PHP session handler for MySQL with locking capacity, preventing concurrent sessions from using the same session.
 * For read-only processes, the locking functionality can be disabled.
 * @author  Charlie Hileman <aiquicorp@gmail.com>
 * @link    https://github.com/aiqui/php_mysql_lock_session
 */

class MySqlLockSessionHandler implements SessionHandlerInterface {

    /** @const integer default number of seconds to wait for locked session */
    const DEFAULT_LOCKED_SESSION_SLEEP = 1;

    /** @const integer maximum number of seconds before a locked session is considered stale */
    const DEFAULT_LOCKED_SESSION_TIMEOUT = 15;

    /** @var string */
    protected static $_sDatabase;

    /** @var string */
    protected static $_sDbHostname;

    /** @var string */
    protected static $_sDbUser;

    /** @var string */
    protected static $_sDbPassword;

    /** @var string */
    protected static $_sDbTable;

    /** @var boolean */
    protected static $_bLockSessions = true;

    /** @var integer */
    protected static $_iSessionSleep = self::DEFAULT_LOCKED_SESSION_SLEEP;

    /** @var integer */
    protected static $_iSessionTimeout = self::DEFAULT_LOCKED_SESSION_TIMEOUT;

    /** @var \PDO */
    protected $_oPdo = null;

    /**
     * @param string $sDatabase
     * @param string $sHostname
     * @param string $sUser
     * @param string $sPassword
     * @param string $sTable
     */
    public static function setDbParams ($sDatabase, $sHostname, $sUser, $sPassword, $sTable) {
        self::$_sDatabase   = $sDatabase;
        self::$_sDbHostname = $sHostname;
        self::$_sDbUser     = $sUser;
        self::$_sDbPassword = $sPassword;
        self::$_sDbTable    = $sTable;
    }

    /**
     * Disable the lock functionality for sessions
     */
    public static function disableLock () {
        self::$_bLockSessions = true;
    }

    /**
     * Enable the lock functionality (enabled by default)
     * @param integer $iSleep
     * @param integer $iTimeout
     * @throws \Exception
     */
    public static function enableLock ($iSleep = self::DEFAULT_LOCKED_SESSION_SLEEP,
        $iTimeout = self::DEFAULT_LOCKED_SESSION_TIMEOUT) {
        if (! self::_isPositiveInteger($iSleep) || ! self::_isPositiveInteger($iTimeout)) {
            throw new \Exception("Invalid sleep parameters for locking sessions");
        }
        self::$_iSessionSleep   = $iSleep;
        self::$_iSessionTimeout = $iTimeout;
        self::$_bLockSessions   = true;
    }

    /**
     * Open session
     * @param  string $sSavePath
     * @param  string $sName
     * @return boolean
     */
    public function open ($sSavePath, $sName) {
        return true;
    }

    /**
     * Close session
     * @return boolean
     */
    public function close () {
        return true;
    }

    /**
     * Read session data
     *
     * @param string $sId
     * @return string
     * @throws \Exception
     */
    public function read ($sId) {
        $this->_validate($sId);
        if (($oRow = $this->_getSession($sId)) !== false) {

            // Lock functionality it turned on
            if (self::$_bLockSessions) {

                // Wait until this session is available
                $iTimeout = 0;
                while ($oRow->locked == 1 && $iTimeout < self::DEFAULT_LOCKED_SESSION_TIMEOUT) {
                    sleep(self::DEFAULT_LOCKED_SESSION_SLEEP);
                    $iTimeout += self::DEFAULT_LOCKED_SESSION_SLEEP;
                    $oRow     = $this->_getSession($sId);
                }

                // Lock this row to prevent a concurrent session
                $this->_lockSession($sId, true);
            }

            return $oRow->data;
        }
        return '';
    }

    /**
     * Write session data
     * @param string $sId
     * @param string $sData
     * @return boolean
     */
    public function write ($sId, $sData) {
        $this->_validate($sId);
        $this->_writeSession($sId, $sData);
        return true;
    }

    /**
     * Destroy session
     * @param  string $sId
     * @return boolean
     */
    public function destroy ($sId) {
        $this->_validate($sId);
        return $this->_deleteSession($sId);
    }

    /**
     * Garbage Collection
     * @param integer $iMaxLifeTime
     * @return boolean
     */
    public function gc ($iMaxLifeTime) {
        $this->_validate();
        $this->_deleteExpired($iMaxLifeTime);
        return true;
    }

    /**
     * Get a session by the ID
     * @param string $sId
     * @return \stdClass|boolean
     */
    protected function _getSession ($sId) {
        $oStmt = $this->_query(
            "SELECT  * 
               FROM  {$this::$_sDbTable}
              WHERE  id = :id",
            [ ':id' => $sId ]
        );
        $oRow = $oStmt->fetchObject();
        $oStmt->closeCursor();
        return $oRow;
    }

    /**
     * @param string $sId
     * @param boolean $bLock
     * @return void
     */
    protected function _lockSession ($sId, $bLock) {
        $this->_query(
            "UPDATE  {$this::$_sDbTable}
                SET  locked = :locked
              WHERE  id = :id",
            [ ':id'     => $sId,
              ':locked' => ( $bLock ? 1 : 0 )]);
    }

    /**
     * @param string $sId
     * @param string $sData
     * @return void
     */
    protected function _writeSession ($sId, $sData) {
        $this->_query(
            "INSERT INTO  {$this::$_sDbTable} (id, data, locked)
                  VALUES  (:id, :data, 0)
 ON DUPLICATE KEY UPDATE  data = :data, 
                          locked = 0,
                          modified = NOW()",
            [ ':id'  => $sId,
              ':data' => $sData ]);
    }

    /**
     * @param string $sId
     * @return boolean
     */
    protected function _deleteSession ($sId) {
        $oStmt = $this->_query(
            "DELETE FROM  {$this::$_sDbTable}
                   WHERE  id = :id",
             [ ':id'  => $sId ]);
        return ($oStmt->rowCount() > 0);
    }

    /**
     * @param integer $iMaxLifeTime
     * @return void
     * @throws \Exception
     */
    protected function _deleteExpired ($iMaxLifeTime) {
        if (! $this::_isPositiveInteger($iMaxLifeTime)) {
            throw new \Exception("Invalid session maximum lifetime");
        }
        $this->_query(
            "DELETE FROM  {$this::$_sDbTable}
                   WHERE  modified < (NOW() - INTERVAL :seconds SECOND)",
            [ ':seconds'  => $iMaxLifeTime ]);
    }

    /**
     * @param integer $iValue
     * @return boolean
     */
    protected static function _isPositiveInteger ($iValue) {
        return ((is_int($iValue) || ctype_digit($iValue)) && (int)$iValue > 0);
    }

    /**
     * @param string|null $sId
     * @throws \Exception
     */
    protected function _validate ($sId = null) {
        if (! self::$_sDatabase || ! self::$_sDbTable || ! self::$_sDbUser) {
            throw new \Exception("PDO parameters are not set correctly");
        }
        if (! preg_match('/^[\w_]+$/', self::$_sDbTable)) {
            throw new \Exception("invalid session table");
        }
        if ($sId && ! preg_match('/^\w+$/', $sId)) {
            throw new \Exception("invalid session ID");
        }
    }


    /**
     * @return \PDO
     */
    protected function _getPdo () {
        $this->_oPdo = new \PDO(sprintf('mysql:dbname=%s;host=%s', self::$_sDatabase, self::$_sDbHostname),
            self::$_sDbUser, self::$_sDbPassword);
        return $this->_oPdo;
    }


    /**
     * @param string $sQuery
     * @param string[] $aParams
     * @return \PDOStatement
     */
    protected function _query ($sQuery, $aParams = null) {
        $oPdo = $this->_getPdo();
        file_put_contents('/tmp/query.txt', "query: $sQuery\n\n", FILE_APPEND);
        if (is_array($aParams) && sizeof($aParams) > 0) {
            $oStmt = $oPdo->prepare($sQuery);
            $oStmt->execute($aParams);
        } else {
            $oStmt = $oPdo->query($sQuery);
        }
        return $oStmt;
    }

}
