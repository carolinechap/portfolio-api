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

  /** @var string */
  protected $env;

  public function __construct(
    #[Autowire(value: '%env(string:FRONT_URL)%')] string $frontUrl,
    #[Autowire(value: '%env(string:APP_ENV)%')] string $env,
  )
  {
    $this->frontUrl = $frontUrl;
    $this->env      = $env;
  }

  #[Route('/', name: 'app_index')]
    public function index(): Response
    {

      if($this->env!== 'dev'){
        return $this->redirect($this->frontUrl);
      }

      return $this->redirect('/_profiler');

    }
}
