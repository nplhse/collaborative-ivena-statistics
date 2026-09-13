<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** @psalm-suppress UnusedClass Registered as DQL CAST_TEXT. */
final class CastTextFunction extends FunctionNode
{
    /** @psalm-suppress PropertyNotSetInConstructor Set by parse(). */
    public Node $field;

    #[\Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf('CAST(%s AS TEXT)', $this->field->dispatch($sqlWalker));
    }

    #[\Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
