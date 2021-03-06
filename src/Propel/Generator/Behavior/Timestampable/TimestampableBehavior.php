<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT License
 */

namespace Propel\Generator\Behavior\Timestampable;

use Propel\Generator\Model\Behavior;

/**
 * Gives a model class the ability to track creation and last modification dates
 * Uses two additional columns storing the creation and update date
 *
 * @author François Zaninotto
 */
class TimestampableBehavior extends Behavior
{
    protected $parameters = [
        'create_column' => 'created_at',
        'update_column' => 'updated_at',
        'delete_column' => 'deleted_at',
        'first'         => false,
        'disable_created_at' => 'false',
        'disable_updated_at' => 'false',
        'disable_deleted_at' => 'true',
    ];


    protected function withUpdatedAt()
    {
        return !$this->booleanValue($this->getParameter('disable_updated_at'));
    }

    protected function withCreatedAt()
    {
        return !$this->booleanValue($this->getParameter('disable_created_at'));
    }

    protected function withDeletedAt()
    {
        return !$this->booleanValue($this->getParameter('disable_deleted_at'));
    }

    /**
     * Add the create_column and update_columns to the current table
     */
    public function modifyTable()
    {
        $table = $this->getTable();

        $fields = ["create", "update", "delete"];
        if ($this->parameters["first"])
            $fields = array_reverse($fields);
        foreach ($fields as $field) {
            switch ($field) {
                case "create" :
                    if ($this->withCreatedAt() && !$table->hasColumn($this->getParameter('create_column'))) {
                        $column_def = array(
                            'name' => $this->getParameter('create_column'),
                            'type' => 'TIMESTAMP'
                        );
                        if ($this->getDatabase() && $this->getDatabase()->getPlatform() && $this->getDatabase()->getPlatform()->getDatabaseType() == "mysql"){
                            //http://jasonbos.co/two-timestamp-columns-in-mysql/
                            $column_def['required'] = true;
                            $column_def['defaultExpr'] = "'0000-00-00 00:00:00'";
                        }
                        $table->addColumn($column_def, $this->parameters["first"]);
                    }
                break;
                case "update" :
                    if ($this->withUpdatedAt() && !$table->hasColumn($this->getParameter('update_column'))) {
                        $column_def = array(
                            'name' => $this->getParameter('update_column'),
                            'type' => 'TIMESTAMP'
                        );
                        if ($this->getDatabase() && $this->getDatabase()->getPlatform() && $this->getDatabase()->getPlatform()->getDatabaseType() == "mysql") {
                            //http://jasonbos.co/two-timestamp-columns-in-mysql/
                            $column_def['required'] = true;
                            $column_def['defaultExpr'] = 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
                        }
                        if ($this->getDatabase() && $this->getDatabase()->getPlatform() && $this->getDatabase()->getPlatform()->getDatabaseType() == "pgsql") {
                            //https://stackoverflow.com/a/9556581
                            $column_def['defaultExpr'] = 'current_timestamp';
                        }
                        $table->addColumn($column_def, $this->parameters["first"]);
                    }
                break;
                case "delete" :
                    if ($this->withDeletedAt() && !$table->hasColumn($this->getParameter('delete_column'))) {
                        $column_def = array(
                            'name' => $this->getParameter('delete_column'),
                            'type' => 'TIMESTAMP'
                        );
                        if ($this->getDatabase() && $this->getDatabase()->getPlatform() && $this->getDatabase()->getPlatform()->getDatabaseType() == "mysql"){
                            //http://jasonbos.co/two-timestamp-columns-in-mysql/
                            $column_def['required'] = true;
                            $column_def['defaultExpr'] = "'0000-00-00 00:00:00'";
                        }
                        $table->addColumn($column_def, $this->parameters["first"], $this->parameters["first"]);
                    }
                break;
            }
        }


    }

    /**
     * Get the setter of one of the columns of the behavior
     *
     * @param  string $column One of the behavior columns, 'create_column' or 'update_column'
     * @return string The related setter, 'setCreatedOn' or 'setUpdatedOn'
     */
    protected function getColumnSetter($column)
    {
        return 'set' . $this->getColumnForParameter($column)->getPhpName();
    }

    protected function getColumnConstant($columnName, $builder)
    {
        return $builder->getColumnConstant($this->getColumnForParameter($columnName));
    }

    /**
     * Add code in ObjectBuilder::preUpdate
     *
     * @return string The code to put at the hook
     */
    public function preUpdate($builder)
    {
        if ($this->withUpdatedAt() && (!$this->getDatabase() || $this->getDatabase()->getPlatform()->getDatabaseType() != "mysql")) {
            return "if (\$this->isModified() && !\$this->isColumnModified(" . $this->getColumnConstant('update_column', $builder) . ")) {
    \$this->" . $this->getColumnSetter('update_column') . "(time());
}";
        }

        return '';
    }

    /**
     * Add code in ObjectBuilder::preInsert
     *
     * @return string The code to put at the hook
     */
    public function preInsert($builder)
    {
        $script = '';

        if ($this->withCreatedAt()) {
            $script .= "
if (!\$this->isColumnModified(" . $this->getColumnConstant('create_column', $builder) . ")) {
    \$this->" . $this->getColumnSetter('create_column') . "(time());
}";
        }

        if ($this->withUpdatedAt() && (!$this->getDatabase() ||$this->getDatabase()->getPlatform()->getDatabaseType() != "mysql")) {
            $script .= "
if (!\$this->isColumnModified(" . $this->getColumnConstant('update_column', $builder) . ")) {
    \$this->" . $this->getColumnSetter('update_column') . "(time());
}";
        }

        return $script;
    }

    public function objectMethods($builder)
    {
        if (!$this->withUpdatedAt()) {
            return '';
        }

        return "
/**
 * Mark the current object so that the update date doesn't get updated during next save
 *
 * @return     \$this|" . $builder->getObjectClassName() . " The current object (for fluent API support)
 */
public function keepUpdateDateUnchanged()
{
    \$this->modifiedColumns[" . $this->getColumnConstant('update_column', $builder) . "] = true;

    return \$this;
}
";
    }

    public function queryMethods($builder)
    {
        $queryClassName = $builder->getQueryClassName();

        $script = '';

        if ($this->withUpdatedAt()) {
            $updateColumnConstant = $this->getColumnConstant('update_column', $builder);
            $script .= "
/**
 * Filter by the latest updated
 *
 * @param      int \$nbDays Maximum age of the latest update in days
 *
 * @return     \$this|$queryClassName The current query, for fluid interface
 */
public function recentlyUpdated(\$nbDays = 7)
{
    return \$this->addUsingAlias($updateColumnConstant, time() - \$nbDays * 24 * 60 * 60, Criteria::GREATER_EQUAL);
}

/**
 * Order by update date desc
 *
 * @return     \$this|$queryClassName The current query, for fluid interface
 */
public function lastUpdatedFirst()
{
    return \$this->addDescendingOrderByColumn($updateColumnConstant);
}

/**
 * Order by update date asc
 *
 * @return     \$this|$queryClassName The current query, for fluid interface
 */
public function firstUpdatedFirst()
{
    return \$this->addAscendingOrderByColumn($updateColumnConstant);
}
";
        }

        if ($this->withCreatedAt()) {
            $createColumnConstant = $this->getColumnConstant('create_column', $builder);
            $script .= "
/**
 * Order by create date desc
 *
 * @return     \$this|$queryClassName The current query, for fluid interface
 */
public function lastCreatedFirst()
{
    return \$this->addDescendingOrderByColumn($createColumnConstant);
}

/**
 * Filter by the latest created
 *
 * @param      int \$nbDays Maximum age of in days
 *
 * @return     \$this|$queryClassName The current query, for fluid interface
 */
public function recentlyCreated(\$nbDays = 7)
{
    return \$this->addUsingAlias($createColumnConstant, time() - \$nbDays * 24 * 60 * 60, Criteria::GREATER_EQUAL);
}

/**
 * Order by create date asc
 *
 * @return     \$this|$queryClassName The current query, for fluid interface
 */
public function firstCreatedFirst()
{
    return \$this->addAscendingOrderByColumn($createColumnConstant);
}
";
        }

        return $script;
    }
}
