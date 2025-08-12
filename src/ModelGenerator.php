<?php

namespace Tablar\CrudGenerator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Class ModelGenerator.
 */
class ModelGenerator
{
    private $functions = null;

    private $table = null;
    private $properties = null;
    private $modelNamespace = 'App';

    /**
     * ModelGenerator constructor.
     *
     * @param string $table
     * @param string $properties
     * @param string $modelNamespace
     */
    public function __construct(string $table, string $properties, string $modelNamespace)
    {
        $this->table = $table;
        $this->properties = $properties;
        $this->modelNamespace = $modelNamespace;
        $this->_init();
    }

    /**
     * Get all the eloquent relations.
     *
     * @return array
     */
    public function getEloquentRelations()
    {
        return [$this->functions, $this->properties];
    }

    private function _init()
    {
        foreach ($this->_getTableRelations() as $relation) {
            if ($relation->ref) {
                $tableKeys = $this->_getTableKeys($relation->ref_table);
                $eloquent = $this->_getEloquent($relation, $tableKeys);
            } else {
                $eloquent = 'hasOne';
            }

            $this->functions .= $this->_getFunction($eloquent, $relation->ref_table, $relation->foreign_key, $relation->local_key);
        }
    }

    /**
     * @param $relation
     * @param $tableKeys
     *
     * @return string
     */
    private function _getEloquent($relation, $tableKeys)
    {
        $eloquent = '';
        foreach ($tableKeys as $tableKey) {
            // dd($tableKey);
            if ($relation->foreign_key == $tableKey->column_names) {
                $eloquent = 'hasMany';

                if ($tableKey->key_name == 'PRIMARY') {
                    $eloquent = 'hasOne';
                } elseif ($tableKey->is_unique == 0 && $tableKey->seq_in_index == 1) {
                    $eloquent = 'hasOne';
                }
            }
        }

        return $eloquent;
    }

    /**
     * @param string $relation
     * @param string $table
     * @param string $foreign_key
     * @param string $local_key
     *
     * @return string
     */
    private function _getFunction(string $relation, string $table, string $foreign_key, string $local_key)
    {
        list($model, $relationName) = $this->_getModelName($table, $relation);
        $relClass = ucfirst($relation);

        switch ($relation) {
            case 'hasOne':
                $this->properties .= "\n * @property $model $$relationName";
                break;
            case 'hasMany':
                $this->properties .= "\n * @property ".$model."[] $$relationName";
                break;
        }

        return '
    /**
     * @return \Illuminate\Database\Eloquent\Relations\\'.$relClass.'
     */
    public function '.$relationName.'()
    {
        return $this->'.$relation.'(\''.$this->modelNamespace.'\\'.$model.'\', \''.$foreign_key.'\', \''.$local_key.'\');
    }
    ';
    }

    /**
     * Get the name relation and model.
     *
     * @param $name
     * @param $relation
     *
     * @return array
     */
    private function _getModelName($name, $relation)
    {
        $class = Str::studly(Str::singular($name));
        $relationName = '';

        switch ($relation) {
            case 'hasOne':
                $relationName = Str::camel(Str::singular($name));
                break;
            case 'hasMany':
                $relationName = Str::camel(Str::plural($name));
                break;
        }

        return [$class, $relationName];
    }

    /**
     * Get all relations from Table.
     *
     * @return array
     */
    private function _getTableRelations()
    {
        $db = DB::getDatabaseName();
        $sql = <<<SQL
WITH fk_constraints AS (
    SELECT
        kcu.table_name,
        kcu.column_name,
        ccu.table_name AS referenced_table_name,
        ccu.column_name AS referenced_column_name
    FROM information_schema.key_column_usage AS kcu
    JOIN information_schema.table_constraints AS tc
        ON tc.constraint_name = kcu.constraint_name
       AND tc.table_schema = kcu.table_schema
       AND tc.constraint_type = 'FOREIGN KEY'
    JOIN information_schema.constraint_column_usage AS ccu
        ON ccu.constraint_name = tc.constraint_name
       AND ccu.table_schema = tc.table_schema
    WHERE kcu.table_schema = 'public'
)
SELECT
    referenced_table_name AS ref_table,
    column_name AS foreign_key,
    referenced_column_name AS local_key,
    '1' AS ref
FROM fk_constraints
WHERE referenced_table_name = '$this->table'

UNION

SELECT
    referenced_table_name AS ref_table,
    referenced_column_name AS foreign_key,
    column_name AS local_key,
    '0' AS ref
FROM fk_constraints
WHERE table_name = '$this->table'
  AND referenced_table_name IS NOT NULL

ORDER BY ref_table ASC;
SQL;

        return DB::select($sql);
    }

    /**
     * Get all Keys from table.
     *
     * @param $table
     *
     * @return array
     */
    private function _getTableKeys($table)
    {
        $sql = <<<SQL
        SELECT
            i.relname AS key_name,
            ix.indisunique AS is_unique,
            ix.indisprimary AS is_primary,
            array_to_string(array_agg(a.attname ORDER BY a.attnum), ', ') AS column_names
        FROM
            pg_class t
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
        WHERE
            t.relkind = 'r'
            AND t.relname = '{$table}'
        GROUP BY
            i.relname, ix.indisunique, ix.indisprimary
        ORDER BY
            i.relname;
        SQL;
        return DB::select($sql);
    }
}
