<?php

namespace NinjaTables\Framework\Database\Query;

use NinjaTables\Framework\Database\BaseGrammar;

interface ExpressionInterface
{
    /**
     * Get the value of the expression.
     *
     * @param  \NinjaTables\Framework\Database\BaseGrammar $grammar
     * @return string|int|float
     */
    public function getValue(BaseGrammar $grammar);
}
