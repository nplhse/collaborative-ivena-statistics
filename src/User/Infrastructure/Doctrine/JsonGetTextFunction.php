<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** @psalm-suppress UnusedClass Registered as DQL JSON_GET_TEXT. */
final class JsonGetTextFunction extends FunctionNode
{
    /** @psalm-suppress PropertyNotSetInConstructor Set by parse(). */
    public Node $jsonField;

    /** @psalm-suppress PropertyNotSetInConstructor Set by parse(). */
    public Node $jsonKey;

    #[\Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            '(%s->>%s)',
            $this->jsonField->dispatch($sqlWalker),
            $this->jsonKey->dispatch($sqlWalker),
        );
    }

    #[\Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->jsonField = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->jsonKey = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
