<?php

declare(strict_types=1);

namespace App\Chat\Service;

enum EmbeddingTaskType: string
{
    case RetrievalQuery = 'RETRIEVAL_QUERY';
    case RetrievalDocument = 'RETRIEVAL_DOCUMENT';
}
