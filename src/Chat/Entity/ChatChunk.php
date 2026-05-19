<?php

declare(strict_types=1);

namespace App\Chat\Entity;

use App\Chat\Repository\ChatChunkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatChunkRepository::class)]
#[ORM\Table(name: 'chat_chunk')]
#[ORM\Index(columns: ['content_hash'], name: 'idx_content_hash')]
class ChatChunk
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $sourceKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(length: 64)]
    private string $contentHash;

    /** @var float[] */
    #[ORM\Column(type: Types::JSON)]
    private array $embedding;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param float[]                   $embedding
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        string $sourceKey,
        string $content,
        string $contentHash,
        array $embedding,
        ?array $metadata = null,
    ) {
        $now = new \DateTimeImmutable();
        $this->sourceKey = $sourceKey;
        $this->content = $content;
        $this->contentHash = $contentHash;
        $this->embedding = $embedding;
        $this->metadata = $metadata;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    /** @return float[] */
    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param float[]                   $embedding
     * @param array<string, mixed>|null $metadata
     */
    public function update(string $content, string $contentHash, array $embedding, ?array $metadata): void
    {
        $this->content = $content;
        $this->contentHash = $contentHash;
        $this->embedding = $embedding;
        $this->metadata = $metadata;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
