<?php

declare(strict_types=1);

use ast\Node;
use Phan\AST\UnionTypeVisitor;
use Phan\Exception\IssueException;
use Phan\Language\Context;
use Phan\PluginV3;
use Phan\PluginV3\PluginAwarePostAnalysisVisitor;
use Phan\PluginV3\PostAnalyzeNodeCapability;

/**
 * This plugin warns if an expression which has types other than `bool` is used in an if/else if.
 *
 * Note that the 'simplify_ast' setting's default of true will interfere with this plugin.
 */
class RedundantDblResultPlugin extends PluginV3 implements PostAnalyzeNodeCapability
{
    /**
     * @return string - name of PluginAwarePreAnalysisVisitor subclass
     *
     * @override
     */
    public static function getPostAnalyzeNodeVisitorClassName(): string
    {
        return RedundantDblResultVisitor::class;
    }
}

/**
 * This visitor checks if statements for conditions ('cond') that are non-booleans.
 */
class RedundantDblResultVisitor extends PluginAwarePostAnalysisVisitor
{
    private $problem_type;
    // A plugin's visitors should not override visit() unless they need to.

    private function checkNode(?Node $node) {
        try {
            $union_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $node);
        } catch (IssueException $_) {
            return true;
        }
        if (!$union_type->isEmpty()) {
            foreach ($union_type->getTypeSet() as $t) {
                if (!$t->isAlwaysTruthy()
                    || ($t->getName() !== "Dbl_Result")) {
                    return true;
                }
            }
            $this->problem_type = $union_type;
            return false;
        } else {
            return true;
        }
    }

    /**
     * @override
     */
    public function visitIfElem(Node $node): Context
    {
        if (!$this->checkNode($node->children["cond"])) {
            $this->emit(
                'PhanPluginRedundantDblResult',
                'Redundant test of type {TYPE} in if clause',
                [(string) $this->problem_type]
            );
        }
        return $this->context;
    }

    /**
     * @override
     */
    public function visitBinaryOp(Node $node): Context
    {
        if ($node->flags === \ast\flags\BINARY_BOOL_XOR
            || $node->flags === \ast\flags\BINARY_BOOL_AND
            || $node->flags === \ast\flags\BINARY_BOOL_OR) {
            if (!$this->checkNode($node->children["left"])) {
                $this->emit(
                    'PhanPluginRedundantDblResult',
                    'Redundant test of type {TYPE} on left of operator',
                    [(string) $this->problem_type]
                );
            }
            if (!$this->checkNode($node->children["right"])) {
                $this->emit(
                    'PhanPluginRedundantDblResult',
                    'Redundant test of type {TYPE} on right of operator',
                    [(string) $this->problem_type]
                );
            }
        }
        return $this->context;
    }
    /**
     * @override
     */
    public function visitUnaryOp(Node $node): Context
    {
        if ($node->flags === \ast\flags\UNARY_BOOL_NOT) {
            if (!$this->checkNode($node->children["expr"])) {
                $this->emit(
                    'PhanPluginRedundantDblResult',
                    'Redundant test of type {TYPE} in operator',
                    [(string) $this->problem_type]
                );
            }
        }
        return $this->context;
    }
}

return new RedundantDblResultPlugin();
