<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A comment on a data extract request (ADR 045), nested like blog comments. A private comment is for the Board only;
 * a reply to a private comment is always private. The visibility is fixed when the comment is posted. A comment
 * refers to the version that was current when it was written, and to the vote whose reason it publishes, if any.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_comment')]
#[ORM\Index(name: 'idx_data_request_comment_request', columns: ['request_id', 'created_at'])]
class DataRequestComment
{
    public const int MAX_LENGTH = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequest $request;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequestVersion $version;

    #[ORM\ManyToOne(inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?DataRequestComment $parent;

    /** @var Collection<int, DataRequestComment> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $children;

    /** Null once the account is deleted; the comment stays as part of the discussion. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private bool $private;

    /** The vote whose reason this comment publishes. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DataRequestVote $vote;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @throws DataRequestException when the body is empty or too long, or a reply to a private comment is public
     */
    public function __construct(DataRequest $request, ?User $author, string $body, bool $private, ?self $parent, \DateTimeImmutable $createdAt, ?DataRequestVote $vote = null)
    {
        $body = trim($body);
        if ('' === $body || mb_strlen($body) > self::MAX_LENGTH) {
            throw new DataRequestException('A comment needs between 1 and 5000 characters.');
        }

        if ($parent instanceof self && $parent->isPrivate() && !$private) {
            throw new DataRequestException('A reply to a private comment is private.');
        }

        if ($parent instanceof self && $parent->getRequest() !== $request) {
            throw new DataRequestException('A reply belongs to the same request.');
        }

        $this->request = $request;
        $this->version = $request->getCurrentVersion();
        $this->author = $author;
        $this->body = $body;
        $this->private = $private;
        $this->parent = $parent;
        $this->createdAt = $createdAt;
        $this->vote = $vote;
        $this->children = new ArrayCollection();
        $parent?->children->add($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): DataRequest
    {
        return $this->request;
    }

    public function getVersion(): DataRequestVersion
    {
        return $this->version;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    /** @return list<self> */
    public function getChildren(): array
    {
        return array_values($this->children->toArray());
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function getVote(): ?DataRequestVote
    {
        return $this->vote;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
