<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IndexController extends AbstractController
{
  /** @var string */
  protected $frontUrl;

  public function __construct(#[Autowire(value: '%env(string:FRONT_URL)%')] string $frontUrl)
  {
    $this->frontUrl = $frontUrl;
  }

  #[Route('/', name: 'app_index')]
    public function index(): Response
    {
      return $this->redirect($this->frontUrl);
    }
}
