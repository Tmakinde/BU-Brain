<?php

namespace App\Modules\Ingestion\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Extracts all database table references from a PHP file's AST.
 *
 * Covers:
 *   - Laravel:      DB::table(), Schema::create(), ->from(), $table property,
 *                   Eloquent relationships, belongsToMany pivot tables
 *   - Yii:          tableName(), createCommand() with inline SQL
 *   - CodeIgniter:  $this->db->get/insert/update/delete/from/join()
 *   - Procedural:   mysqli_query(), mysqli_prepare(), mysql_query(),
 *                   PDO ->query() / ->prepare() / ->exec()
 *   - Custom:       Any ClassName::query() wrapper pattern
 *   - Raw SQL:      Scans string literals for FROM/JOIN/INTO/UPDATE keywords
 *
 * This replaces the regex-based extractTableReferences() method.
 * Because we work on AST nodes — not raw text — we correctly handle:
 *   - Nested method calls
 *   - Strings inside comments (ignored by the parser)
 *   - Multi-line queries
 *   - Any whitespace variation
 *
 * Usage:
 *   $visitor = new TableReferenceVisitor();
 *   $traverser->addVisitor($visitor);
 *   $traverser->traverse($ast);
 *   $tables = $visitor->getTables();
 */
class TableReferenceVisitor extends NodeVisitorAbstract
{
    private array $tables = [];

    // Table names to ignore — SQL keywords and common false positives
    private const IGNORE = [
        'function', 'class', 'public', 'private', 'protected', 'static',
        'return', 'new', 'echo', 'print', 'array', 'null', 'true', 'false',
        'select', 'where', 'set', 'dual', 'table', 'values', 'into',
        'row', 'result', 'data', 'query', 'column', 'index', 'join',
        'information_schema', 'performance_schema', 'sys', 'mysql',
    ];

    public function enterNode(Node $node): null
    {
        // -----------------------------------------------------------------
        // Static method calls: DB::table(), Schema::create(), AnyClass::query()
        // -----------------------------------------------------------------
        if ($node instanceof Node\Expr\StaticCall) {
            $this->handleStaticCall($node);
        }

        // -----------------------------------------------------------------
        // Method calls: ->table(), ->from(), ->get(), $this->db->insert()
        // ->query(), ->prepare(), ->exec()
        // -----------------------------------------------------------------
        if ($node instanceof Node\Expr\MethodCall) {
            $this->handleMethodCall($node);
        }

        // -----------------------------------------------------------------
        // Function calls: mysqli_query(), mysqli_prepare(), mysql_query()
        // -----------------------------------------------------------------
        if ($node instanceof Node\Expr\FuncCall) {
            $this->handleFuncCall($node);
        }

        // -----------------------------------------------------------------
        // Class property: protected $table = 'table_name'
        // -----------------------------------------------------------------
        if ($node instanceof Node\Stmt\Property) {
            $this->handlePropertyDeclaration($node);
        }

        // -----------------------------------------------------------------
        // Class method return: tableName() { return '{{%table_name}}'; }
        // Covers Yii ActiveRecord tableName() convention
        // -----------------------------------------------------------------
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->handleClassMethod($node);
        }

        return null;
    }

    // =========================================================================
    // Handlers
    // =========================================================================

    /**
     * Handle static method calls.
     *
     * Covers:
     *   DB::table('invoices')
     *   Schema::create('invoices', ...)
     *   Schema::drop('invoices')
     *   AnyClass::query('SELECT * FROM invoices')  ← custom wrappers
     */
    private function handleStaticCall(Node\Expr\StaticCall $node): void
    {
        if (!($node->name instanceof Node\Identifier)) {
            return;
        }

        $method = $node->name->toString();

        // DB::table('table_name')
        if ($method === 'table' && $this->getClassName($node) === 'DB') {
            $this->extractFirstStringArg($node->args);
            return;
        }

        // Schema::create/drop/table/rename/hasTable/hasColumn('table_name')
        if ($this->getClassName($node) === 'Schema') {
            $this->extractFirstStringArg($node->args);
            return;
        }

        // AnyClass::query('SELECT ... FROM table_name ...')
        // Catches scripting_utils::query(), custom wrappers, etc.
        if ($method === 'query') {
            $this->extractTablesFromSqlArgs($node->args);
            return;
        }
    }

    /**
     * Handle instance method calls.
     *
     * Covers:
     *   ->from('table_name')                Laravel Query Builder
     *   ->belongsToMany(Model::class, 'pivot_table')
     *   $this->db->get('table_name')        CodeIgniter
     *   $this->db->insert('table_name')     CodeIgniter
     *   $this->db->query('SELECT ...')      CodeIgniter
     *   createCommand('SELECT ...')         Yii
     *   ->query('SELECT ...')              PDO / any wrapper
     *   ->prepare('INSERT INTO ...')       PDO
     *   ->exec('DELETE FROM ...')          PDO
     */
    private function handleMethodCall(Node\Expr\MethodCall $node): void
    {
        if (!($node->name instanceof Node\Identifier)) {
            return;
        }

        $method = $node->name->toString();

        // ->from('table_name')
        if ($method === 'from') {
            $this->extractFirstStringArg($node->args);
            return;
        }

        // ->belongsToMany(Model::class, 'pivot_table_name')
        if ($method === 'belongsToMany' && isset($node->args[1])) {
            $this->extractStringArg($node->args[1]);
            return;
        }

        // Eloquent relationships → guess table from model class name
        if (in_array($method, ['hasMany', 'hasOne', 'belongsTo', 'hasManyThrough', 'hasOneThrough'])) {
            if (!empty($node->args)) {
                $firstArg = $node->args[0]->value;
                // hasMany(Invoice::class) → first arg is a class constant fetch
                if ($firstArg instanceof Node\Expr\ClassConstFetch) {
                    if ($firstArg->class instanceof Node\Name) {
                        $this->tables[] = $this->classNameToTableGuess(
                            $firstArg->class->getLast()
                        );
                    }
                }
            }
            return;
        }

        // CodeIgniter: $this->db->get/insert/update/delete/from/join('table')
        if (in_array($method, ['get', 'insert', 'update', 'delete', 'join', 'insert_batch', 'update_batch'])) {
            $this->extractFirstStringArg($node->args);
            return;
        }

        // CodeIgniter / PDO / custom: ->query() ->prepare() ->exec() with SQL string
        if (in_array($method, ['query', 'prepare', 'exec', 'createCommand'])) {
            $this->extractTablesFromSqlArgs($node->args);
            return;
        }
    }

    /**
     * Handle plain function calls.
     *
     * Covers:
     *   mysqli_query($conn, 'SELECT * FROM table_name')
     *   mysqli_prepare($conn, 'INSERT INTO table_name')
     *   mysql_query('SELECT * FROM table_name')
     */
    private function handleFuncCall(Node\Expr\FuncCall $node): void
    {
        if (!($node->name instanceof Node\Name)) {
            return;
        }

        $funcName = $node->name->toString();

        // mysqli_query($conn, $sql) — SQL is second argument
        if ($funcName === 'mysqli_query' || $funcName === 'mysqli_prepare') {
            if (isset($node->args[1])) {
                $this->extractTablesFromSqlArg($node->args[1]);
            }
            return;
        }

        // mysql_query($sql) — SQL is first argument
        if ($funcName === 'mysql_query') {
            $this->extractTablesFromSqlArgs($node->args);
            return;
        }
    }

    /**
     * Handle class property declarations.
     *
     * Covers:
     *   protected $table = 'invoices';   ← Eloquent model
     */
    private function handlePropertyDeclaration(Node\Stmt\Property $node): void
    {
        foreach ($node->props as $prop) {
            if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                $this->addTable($prop->default->value);
            }
        }
    }

    /**
     * Handle class method declarations.
     *
     * Covers:
     *   public static function tableName() { return '{{%invoices}}'; }  ← Yii
     *   public static function tableName() { return 'invoices'; }       ← Yii
     */
    private function handleClassMethod(Node\Stmt\ClassMethod $node): void
    {
        if ($node->name->toString() !== 'tableName') {
            return;
        }

        if (!$node->stmts) {
            return;
        }

        foreach ($node->stmts as $stmt) {
            if (!($stmt instanceof Node\Stmt\Return_)) {
                continue;
            }

            if (!($stmt->expr instanceof Node\Scalar\String_)) {
                continue;
            }

            // Strip Yii's {{%table_name}} prefix notation
            $tableName = preg_replace('/^\{\{%(.+)\}\}$/', '$1', $stmt->expr->value);
            $this->addTable($tableName);
        }
    }

    // =========================================================================
    // Argument helpers
    // =========================================================================

    /**
     * Extract a table name from the first string argument of a call.
     */
    private function extractFirstStringArg(array $args): void
    {
        if (!empty($args)) {
            $this->extractStringArg($args[0]);
        }
    }

    /**
     * Extract a table name from a single string argument node.
     */
    private function extractStringArg(Node\Arg $arg): void
    {
        if ($arg->value instanceof Node\Scalar\String_) {
            $this->addTable($arg->value->value);
        }
    }

    /**
     * Scan all string arguments for SQL keywords and extract table names.
     * Used for raw SQL calls where the table name is embedded in a query string.
     */
    private function extractTablesFromSqlArgs(array $args): void
    {
        foreach ($args as $arg) {
            $this->extractTablesFromSqlArg($arg);
        }
    }

    /**
     * Scan a single argument node for SQL table references.
     */
    private function extractTablesFromSqlArg(Node\Arg $arg): void
    {
        if (!($arg->value instanceof Node\Scalar\String_)) {
            return;
        }

        $sql = $arg->value->value;
        $this->extractTablesFromSqlString($sql);
    }

    /**
     * Extract table names from a raw SQL string.
     * Matches FROM / JOIN / INTO / UPDATE followed by a table name.
     */
    private function extractTablesFromSqlString(string $sql): void
    {
        preg_match_all(
            '/\b(?:FROM|JOIN|INTO|UPDATE)\s+[`"\']?(\w+)[`"\']?/i',
            $sql,
            $matches
        );

        foreach ($matches[1] as $table) {
            $this->addTable($table);
        }
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    /**
     * Get the class name from a static call node.
     */
    private function getClassName(Node\Expr\StaticCall $node): ?string
    {
        if ($node->class instanceof Node\Name) {
            return $node->class->toString();
        }
        return null;
    }

    /**
     * Add a table name after validation.
     * Skips empty strings, short strings, and known false positives.
     */
    private function addTable(string $table): void
    {
        $table = trim($table);

        if (strlen($table) <= 2) {
            return;
        }

        if (in_array(strtolower($table), self::IGNORE)) {
            return;
        }

        $this->tables[] = $table;
    }

    /**
     * Guess a table name from an Eloquent/ActiveRecord model class name.
     *
     * Examples:
     *   Invoice      → invoices
     *   OrderProduct → order_products
     *   CustomerNote → customer_notes
     */
    private function classNameToTableGuess(string $className): string
    {
        $snake = strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($className)));
        return rtrim($snake, 's') . 's';
    }

    /**
     * Return all unique table names found.
     */
    public function getTables(): array
    {
        return array_values(array_unique($this->tables));
    }
}