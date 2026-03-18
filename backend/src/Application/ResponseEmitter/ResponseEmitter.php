<?php
declare(strict_types=1);

namespace App\Application\ResponseEmitter;

use Psr\Http\Message\ResponseInterface;
use Slim\ResponseEmitter as SlimResponseEmitter;

class ResponseEmitter extends SlimResponseEmitter
{
    public function emit(ResponseInterface $response): void
    {
        // This method is intentionally empty so that we can customize
        // the response emitter if needed in the future
        parent::emit($response);
    }
}