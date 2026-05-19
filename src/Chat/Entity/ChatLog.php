<?php

declare(strict_types=1);

namespace App\Chat\Entity;

use App\Chat\Repository\ChatLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatLogRepository::class)]
#[ORM\Table(name: 'chat_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created_at')]
class ChatLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $question;

    #[ORM\Column(type: Types::TEXT)]
    private string $answer;

    #[ORM\Column(type: Types::FLOAT)]
    private float $topScore;

    /** @var string[]|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $chunksUsed = null;

    #[ORM\Column(enumType: ChatOutcome::class)]
    private ChatOutcome $outcome;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param string[]|null $chunksUsed */
    public function __construct(
        string $question,
        string $answer,
        float $topScore,
        ?array $chunksUsed,
        ChatOutcome $outcome,
    ) {
        $this->question = $question;
        $this->answer = $answer;
        $this->topScore = $topScore;
        $this->chunksUsed = $chunksUsed;
        $this->outcome = $outcome;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOutcome(): ChatOutcome
    {
        return $this->outcome;
    }
}