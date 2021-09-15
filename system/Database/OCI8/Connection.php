<?php

/**
 * This file is part of CodeIgniter 4 framework.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace CodeIgniter\Database\OCI8;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use ErrorException;
use stdClass;

/**
 * Connection for Postgre
 *
 * @property string|null $latestInsertedTableName
 * @property int|null    $rowId
 */
class Connection extends BaseConnection implements ConnectionInterface
{
    /**
     * Database driver
     *
     * @var string
     */
    public $DBDriver = 'OCI8';

    /**
     * Identifier escape character
     *
     * @var string
     */
    public $escapeChar = '"';

    /**
     * List of reserved identifiers
     *
     * Identifiers that must NOT be escaped.
     *
     * @var array
     */
    protected $reservedIdentifiers = [
        '*',
        'rownum',
    ];

    protected $validDSNs = [
        'tns' => '/^\(DESCRIPTION=(\(.+\)){2,}\)$/', // TNS
        // Easy Connect string (Oracle 10g+)
        'ec' => '/^(\/\/)?[a-z0-9.:_-]+(:[1-9][0-9]{0,4})?(\/[a-z0-9$_]+)?(:[^\/])?(\/[a-z0-9$_]+)?$/i',
        'in' => '/^[a-z0-9$_]+$/i', // Instance name (defined in tnsnames.ora)
    ];

    /**
     * Reset $stmtId flag
     *
     * Used by storedProcedure() to prevent execute() from
     * re-setting the statement ID.
     */
    protected $resetStmtId = true;

    /**
     * Statement ID
     *
     * @var resource
     */
    public $stmtId;

    /**
     * Commit mode flag
     *
     * @var int
     */
    public $commitMode = OCI_COMMIT_ON_SUCCESS;

    /**
     * Cursor ID
     *
     * @var resource
     */
    public $cursorId;

    /**
     * RowID
     *
     * @var int|null
     */
    public $rowId;

    /**
     * Latest inserted table name.
     *
     * @var string|null
     */
    public $latestInsertedTableName;

    /**
     * confirm DNS format.
     */
    private function isValidDSN(): bool
    {
        foreach ($this->validDSNs as $regexp) {
            if (preg_match($regexp, $this->DSN)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Connect to the database.
     *
     * @return mixed
     */
    public function connect(bool $persistent = false)
    {
        if (empty($this->DSN) && ! $this->isValidDSN()) {
            $this->buildDSN();
        }

        $func = ($persistent === true) ? 'oci_pconnect' : 'oci_connect';

        return empty($this->charset)
            ? $func($this->username, $this->password, $this->DSN)
            : $func($this->username, $this->password, $this->DSN, $this->charset);
    }

    /**
     * Keep or establish the connection if no queries have been sent for
     * a length of time exceeding the server's idle timeout.
     *
     * @return void
     */
    public function reconnect()
    {
    }

    /**
     * Close the database connection.
     *
     * @return void
     */
    protected function _close()
    {
        if (is_resource($this->cursorId)) {
            oci_free_statement($this->cursorId);
        }
        if (is_resource($this->stmtId)) {
            oci_free_statement($this->stmtId);
        }
        oci_close($this->connID);
    }

    /**
     * Select a specific database table to use.
     */
    public function setDatabase(string $databaseName): bool
    {
        return false;
    }

    /**
     * Returns a string containing the version of the database being used.
     */
    public function getVersion(): string
    {
        if (isset($this->dataCache['version'])) {
            return $this->dataCache['version'];
        }

        if (! $this->connID || ($versionString = oci_server_version($this->connID)) === false) {
            return '';
        }
        if (preg_match('#Release\s(\d+(?:\.\d+)+)#', $versionString, $match)) {
            return $this->dataCache['version'] = $match[1];
        }

        return '';
    }

    /**
     * Executes the query against the database.
     *
     * @return bool|resource
     */
    protected function execute(string $sql)
    {
        try {
            if ($this->resetStmtId === true) {
                $sql = rtrim($sql, ';');
                if (strpos(ltrim($sql), 'BEGIN') === 0) {
                    $sql .= ';';
                }
                $this->stmtId = oci_parse($this->connID, $sql);
            }

            if (strpos($sql, 'RETURNING ROWID INTO :CI_OCI8_ROWID') !== false) {
                oci_bind_by_name($this->stmtId, ':CI_OCI8_ROWID', $this->rowId, 255);
            }

            oci_set_prefetch($this->stmtId, 1000);

            return (oci_execute($this->stmtId, $this->commitMode)) ? $this->stmtId : false;
        } catch (ErrorException $e) {
            log_message('error', $e->getMessage());

            if ($this->DBDebug) {
                throw $e;
            }
        }

        return false;
    }

    /**
     * Returns the total number of rows affected by this query.
     */
    public function affectedRows(): int
    {
        return oci_num_rows($this->stmtId);
    }

    /**
     * Generates the SQL for listing tables in a platform-dependent manner.
     */
    protected function _listTables(bool $prefixLimit = false): string
    {
        $sql = 'SELECT "TABLE_NAME" FROM "USER_TABLES"';

        if ($prefixLimit !== false && $this->DBPrefix !== '') {
            return $sql . ' WHERE "TABLE_NAME" LIKE \'' . $this->escapeLikeString($this->DBPrefix) . "%' "
                    . sprintf($this->likeEscapeStr, $this->likeEscapeChar);
        }

        return $sql;
    }

    /**
     * Generates a platform-specific query string so that the column names can be fetched.
     */
    protected function _listColumns(string $table = ''): string
    {
        if (strpos($table, '.') !== false) {
            sscanf($table, '%[^.].%s', $owner, $table);
        } else {
            $owner = $this->username;
        }

        return 'SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS
			WHERE UPPER(OWNER) = ' . $this->escape(strtoupper($owner)) . '
				AND UPPER(TABLE_NAME) = ' . $this->escape(strtoupper($this->DBPrefix . $table));
    }

    /**
     * Returns an array of objects with field data
     *
     * @throws DatabaseException
     *
     * @return stdClass[]
     */
    protected function _fieldData(string $table): array
    {
        if (strpos($table, '.') !== false) {
            sscanf($table, '%[^.].%s', $owner, $table);
        } else {
            $owner = $this->username;
        }

        $sql = 'SELECT COLUMN_NAME, DATA_TYPE, CHAR_LENGTH, DATA_PRECISION, DATA_LENGTH, DATA_DEFAULT, NULLABLE
			FROM ALL_TAB_COLUMNS
			WHERE UPPER(OWNER) = ' . $this->escape(strtoupper($owner)) . '
				AND UPPER(TABLE_NAME) = ' . $this->escape(strtoupper($table));

        if (($query = $this->query($sql)) === false) {
            throw new DatabaseException(lang('Database.failGetFieldData'));
        }
        $query = $query->getResultObject();

        $retval = [];

        for ($i = 0, $c = count($query); $i < $c; $i++) {
            $retval[$i]       = new stdClass();
            $retval[$i]->name = $query[$i]->COLUMN_NAME;
            $retval[$i]->type = $query[$i]->DATA_TYPE;

            $length = ($query[$i]->CHAR_LENGTH > 0)
                ? $query[$i]->CHAR_LENGTH : $query[$i]->DATA_PRECISION;
            if ($length === null) {
                $length = $query[$i]->DATA_LENGTH;
            }
            $retval[$i]->max_length = $length;

            $default = $query[$i]->DATA_DEFAULT;
            if ($default === null && $query[$i]->NULLABLE === 'N') {
                $default = '';
            }
            $retval[$i]->default  = $default;
            $retval[$i]->nullable = $query[$i]->NULLABLE === 'Y';
        }

        return $retval;
    }

    /**
     * Returns an array of objects with index data
     *
     * @throws DatabaseException
     *
     * @return stdClass[]
     */
    protected function _indexData(string $table): array
    {
        if (strpos($table, '.') !== false) {
            sscanf($table, '%[^.].%s', $owner, $table);
        } else {
            $owner = $this->username;
        }

        $sql = 'SELECT AIC.INDEX_NAME, UC.CONSTRAINT_TYPE, AIC.COLUMN_NAME '
            . ' FROM ALL_IND_COLUMNS AIC '
            . ' LEFT JOIN USER_CONSTRAINTS UC ON AIC.INDEX_NAME = UC.CONSTRAINT_NAME AND AIC.TABLE_NAME = UC.TABLE_NAME '
            . 'WHERE AIC.TABLE_NAME = ' . $this->escape(strtolower($table)) . ' '
            . 'AND AIC.TABLE_OWNER = ' . $this->escape(strtoupper($owner)) . ' '
            . ' ORDER BY UC.CONSTRAINT_TYPE, AIC.COLUMN_POSITION';

        if (($query = $this->query($sql)) === false) {
            throw new DatabaseException(lang('Database.failGetIndexData'));
        }
        $query = $query->getResultObject();

        $retVal          = [];
        $constraintTypes = [
            'P' => 'PRIMARY',
            'U' => 'UNIQUE',
        ];

        foreach ($query as $row) {
            if (isset($retVal[$row->INDEX_NAME])) {
                $retVal[$row->INDEX_NAME]->fields[] = $row->COLUMN_NAME;

                continue;
            }

            $retVal[$row->INDEX_NAME]         = new \stdClass();
            $retVal[$row->INDEX_NAME]->name   = $row->INDEX_NAME;
            $retVal[$row->INDEX_NAME]->fields = [$row->COLUMN_NAME];
            $retVal[$row->INDEX_NAME]->type   = $constraintTypes[$row->CONSTRAINT_TYPE] ?? 'INDEX';
        }

        return $retVal;
    }

    /**
     * Returns an array of objects with Foreign key data
     *
     * @throws DatabaseException
     *
     * @return stdClass[]
     */
    protected function _foreignKeyData(string $table): array
    {
        $sql = 'SELECT
                 acc.constraint_name,
                 acc.table_name,
                 acc.column_name,
                 ccu.table_name foreign_table_name,
                 accu.column_name foreign_column_name
  FROM all_cons_columns acc
  JOIN all_constraints ac
      ON acc.owner = ac.owner
      AND acc.constraint_name = ac.constraint_name
  JOIN all_constraints ccu
      ON ac.r_owner = ccu.owner
      AND ac.r_constraint_name = ccu.constraint_name
  JOIN all_cons_columns accu
      ON accu.constraint_name = ccu.constraint_name
      AND accu.table_name = ccu.table_name
  WHERE ac.constraint_type = ' . $this->escape('R') . '
      AND acc.table_name = ' . $this->escape($table);

        if (($query = $this->query($sql)) === false) {
            throw new DatabaseException(lang('Database.failGetForeignKeyData'));
        }
        $query = $query->getResultObject();

        $retVal = [];

        foreach ($query as $row) {
            $obj                      = new \stdClass();
            $obj->constraint_name     = $row->CONSTRAINT_NAME;
            $obj->table_name          = $row->TABLE_NAME;
            $obj->column_name         = $row->COLUMN_NAME;
            $obj->foreign_table_name  = $row->FOREIGN_TABLE_NAME;
            $obj->foreign_column_name = $row->FOREIGN_COLUMN_NAME;
            $retVal[]                 = $obj;
        }

        return $retVal;
    }

    /**
     * Returns platform-specific SQL to disable foreign key checks.
     *
     * @return string
     */
    protected function _disableForeignKeyChecks()
    {
        return <<<'SQL'
            BEGIN
              FOR c IN
              (SELECT c.owner, c.table_name, c.constraint_name
               FROM user_constraints c, user_tables t
               WHERE c.table_name = t.table_name
               AND c.status = 'ENABLED'
               AND c.constraint_type = 'R'
               AND t.iot_type IS NULL
               ORDER BY c.constraint_type DESC)
              LOOP
                dbms_utility.exec_ddl_statement('alter table "' || c.owner || '"."' || c.table_name || '" disable constraint "' || c.constraint_name || '"');
              END LOOP;
            END;
            SQL;
    }

    /**
     * Returns platform-specific SQL to enable foreign key checks.
     *
     * @return string
     */
    protected function _enableForeignKeyChecks()
    {
        return <<<'SQL'
            BEGIN
              FOR c IN
              (SELECT c.owner, c.table_name, c.constraint_name
               FROM user_constraints c, user_tables t
               WHERE c.table_name = t.table_name
               AND c.status = 'DISABLED'
               AND c.constraint_type = 'R'
               AND t.iot_type IS NULL
               ORDER BY c.constraint_type DESC)
              LOOP
                dbms_utility.exec_ddl_statement('alter table "' || c.owner || '"."' || c.table_name || '" enable constraint "' || c.constraint_name || '"');
              END LOOP;
            END;
            SQL;
    }

    /**
     * Get cursor. Returns a cursor from the database
     *
     * @return resource
     */
    public function getCursor()
    {
        return $this->cursorId = oci_new_cursor($this->connID);
    }

    /**
     * Stored Procedure.  Executes a stored procedure
     *
     * @param string $package   package name in which the stored procedure is in
     * @param string $procedure stored procedure name to execute
     * @param array  $params    parameters
     *
     * @return mixed
     *
     * params array keys
     *
     * KEY      OPTIONAL  NOTES
     * name     no        the name of the parameter should be in :<param_name> format
     * value    no        the value of the parameter.  If this is an OUT or IN OUT parameter,
     *                    this should be a reference to a variable
     * type     yes       the type of the parameter
     * length   yes       the max size of the parameter
     */
    public function storedProcedure(string $package, string $procedure, array $params)
    {
        if ($package === '' || $procedure === '') {
            throw new DatabaseException(lang('Database.invalidArgument', [$package . $procedure]));
        }

        // Build the query string
        $sql = 'BEGIN ' . $package . '.' . $procedure . '(';

        $haveCursor = false;

        foreach ($params as $param) {
            $sql .= $param['name'] . ',';

            if (isset($param['type']) && $param['type'] === OCI_B_CURSOR) {
                $haveCursor = true;
            }
        }
        $sql = trim($sql, ',') . '); END;';

        $this->resetStmtId = false;
        $this->stmtId      = oci_parse($this->connID, $sql);
        $this->bindParams($params);
        $result            = $this->query($sql, false, $haveCursor);
        $this->resetStmtId = true;

        return $result;
    }

    /**
     * Bind parameters
     *
     * @param array $params
     *
     * @return void
     */
    protected function bindParams($params)
    {
        if (! is_array($params) || ! is_resource($this->stmtId)) {
            return;
        }

        foreach ($params as $param) {
            foreach (['name', 'value', 'type', 'length'] as $val) {
                if (! isset($param[$val])) {
                    $param[$val] = '';
                }
            }

            oci_bind_by_name($this->stmtId, $param['name'], $param['value'], $param['length'], $param['type']);
        }
    }

    /**
     * Returns the last error code and message.
     *
     * Must return an array with keys 'code' and 'message':
     *
     *  return ['code' => null, 'message' => null);
     */
    public function error(): array
    {
        // oci_error() returns an array that already contains
        // 'code' and 'message' keys, but it can return false
        // if there was no error ....
        $error     = oci_error();
        $resources = [$this->cursorId, $this->stmtId, $this->connID];

        foreach ($resources as $resource) {
            if (is_resource($resource)) {
                $error = oci_error($resource);
                break;
            }
        }

        return is_array($error)
            ? $error
            : [
                'code'    => '',
                'message' => '',
            ];
    }

    /**
     * Insert ID
     */
    public function insertID(): int
    {
        if (empty($this->rowId) || empty($this->latestInsertedTableName)) {
            return 0;
        }

        $indexs      = $this->getIndexData($this->latestInsertedTableName);
        $fieldDatas = $this->getFieldData($this->latestInsertedTableName);

        if (! $indexs || ! $fieldDatas) {
            return 0;
        }

        $columnTypeList    = array_column($fieldDatas, 'type', 'name');
        $primaryColumnName = '';

        foreach ($indexs as $index) {
            if ($index->type !== 'PRIMARY' || count($index->fields) !== 1) {
                continue;
            }

            $primaryColumnName = $this->protectIdentifiers($index->fields[0], false, false);
            $primaryColumnType = $columnTypeList[$primaryColumnName];

            if ($primaryColumnType !== 'NUMBER') {
                continue;
            }
        }

        if (! $primaryColumnName) {
            return 0;
        }

        $table = $this->protectIdentifiers($this->latestInsertedTableName, true);
        $query = $this->query('SELECT ' . $this->protectIdentifiers($primaryColumnName, false) . ' SEQ FROM ' . $table . ' WHERE ROWID = ?', $this->rowId)->getRow();

        return (int) ($query->SEQ ?? 0);
    }

    /**
     * Build a DSN from the provided parameters
     *
     * @return void
     */
    protected function buildDSN()
    {
        if ($this->DSN !== '') {
            $this->DSN = '';
        }

        // Legacy support for TNS in the hostname configuration field
        $this->hostname = str_replace(["\n", "\r", "\t", ' '], '', $this->hostname);

        if (preg_match($this->validDSNs['tns'], $this->hostname)) {
            $this->DSN = $this->hostname;

            return;
        }

        $isEasyConnectableHostName = $this->hostname !== '' && strpos($this->hostname, '/') === false && strpos($this->hostname, ':') === false;
        $easyConnectablePort       = ((! empty($this->port) && ctype_digit($this->port)) ? ':' . $this->port : '');
        $easyConnectableDatabase   = ($this->database !== '' ? '/' . ltrim($this->database, '/') : '');

        if ($isEasyConnectableHostName && ($easyConnectablePort !== '' || $easyConnectableDatabase !== '')) {
            /* If the hostname field isn't empty, doesn't contain
             * ':' and/or '/' and if port and/or database aren't
             * empty, then the hostname field is most likely indeed
             * just a hostname. Therefore we'll try and build an
             * Easy Connect string from these 3 settings, assuming
             * that the database field is a service name.
             */
            $this->DSN = $this->hostname
                . $easyConnectablePort
                . $easyConnectableDatabase;

            if (preg_match($this->validDSNs['ec'], $this->DSN)) {
                return;
            }
        }

        /* At this point, we can only try and validate the hostname and
         * database fields separately as DSNs.
         */
        if (preg_match($this->validDSNs['ec'], $this->hostname) || preg_match($this->validDSNs['in'], $this->hostname)) {
            $this->DSN = $this->hostname;

            return;
        }

        $this->database = str_replace(["\n", "\r", "\t", ' '], '', $this->database);

        foreach ($this->validDSNs as $regexp) {
            if (preg_match($regexp, $this->database)) {
                return;
            }
        }

        /* Well - OK, an empty string should work as well.
         * PHP will try to use environment variables to
         * determine which Oracle instance to connect to.
         */
        $this->DSN = '';
    }

    /**
     * Begin Transaction
     */
    protected function _transBegin(): bool
    {
        $this->commitMode = OCI_NO_AUTO_COMMIT;

        return true;
    }

    /**
     * Commit Transaction
     */
    protected function _transCommit(): bool
    {
        $this->commitMode = OCI_COMMIT_ON_SUCCESS;

        return oci_commit($this->connID);
    }

    /**
     * Rollback Transaction
     */
    protected function _transRollback(): bool
    {
        $this->commitMode = OCI_COMMIT_ON_SUCCESS;

        return oci_rollback($this->connID);
    }

    /**
     * Returns the name of the current database being used.
     */
    public function getDatabase(): string
    {
        if (empty($this->database)) {
            $this->database = $this->query('SELECT DEFAULT_TABLESPACE FROM USER_USERS')->getRow()->DEFAULT_TABLESPACE ?? '';
        }

        return empty($this->database) ? '' : $this->database;
    }
}
