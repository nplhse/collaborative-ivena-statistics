<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Subselect;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * ADD_SUBQUERIES((SELECT ...), (SELECT ...), ...) adds the results of scalar subqueries.
 *
 * DQL cannot combine subqueries with `+`. Two separate counts can each use an index-only
 * scan, while one count with `OR` has to read every matching row.
 *
 * @psalm-suppress UnusedClass Registered as DQL ADD_SUBQUERIES.
 */
final class AddSubqueriesFunction extends FunctionNode
{
    /** @var list<Subselect> */
    public array $subselects = [];

    #[\Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return '('.implode(' + ', array_map(
            static fn (Subselect $subselect): string => 'COALESCE(('.$sqlWalker->walkSubselect($subselect).'), 0)',
            $this->subselects,
        )).')';
    }

    #[\Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->subselects[] = $this->subselect($parser);
        while ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->subselects[] = $this->subselect($parser);
        }
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    private function subselect(Parser $parser): Subselect
    {
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $subselect = $parser->Subselect();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);

        return $subselect;
    }
}
